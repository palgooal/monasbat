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
    /**
     * طبقة النقل المشتركة (Supervisor Invitation Delivery via Cartat —
     * تنفيذ، Option B) — تحل محل الدوال الأربع الخاصة السابقة (api_request/
     * send_text_message/send_media_message/format_wa_number) وinterpret_
     * cartat_result، الآن جميعها في includes/class-pge-cartat-transport.php
     * حصراً (نفس منطقها الداخلي بلا أي تغيير). هذا الكلاس يستهلكها فقط، لا
     * يعيد تعريف أي منها — تطبيق نقل Cartat الوحيد في المشروع بعد هذا
     * الاستخراج.
     */
    private PGE_Cartat_Transport $transport;

    public function __construct()
    {
        $this->transport = new PGE_Cartat_Transport();

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
            // ══════════════════════════════════════════════════════════
            // RC1 Cartat ACK Compatibility Fix — دليل إنتاجي مؤكَّد: كارتات
            // يُصدر فعلياً ack=2 و/أو ack=3 لمسار ربط LID تحديداً، لا ack=1
            // حصراً كما كان مفترَضاً سابقاً. القيد السابق (=== 1) كان يمنع
            // بناء الخريطة كلياً كلما وصل ACK بمستوى غير 1، رغم صحة تطابق
            // msg_id ووجود pending. الشرط الجديد: أي مستوى ACK موجب (>= 1
            // — مُرسَل/مُسلَّم/مقروء) مؤهَّل؛ 0 (لم يُرسَل بعد) وأي قيمة سالبة
            // (فشل صريح) يبقيان غير مؤهَّلين، كما هما بلا أي تغيير إضافي.
            if ((int)($payload['ack'] ?? 0) >= 1) {
                $msg_id  = $payload['id']  ?? '';
                $raw_to  = $payload['to']  ?? '';
                $to_bare = preg_replace('/@.*$/', '', $raw_to);

                // شرط إضافي مطلوب صراحة: لا نبني خريطة LID إلا إذا كانت
                // الوجهة LID فعلاً (raw_to يحوي @lid) — نفس فحص @lid المستخدَم
                // أصلاً في مسار message_received أدناه، بلا دالة/منطق جديد.
                if ($msg_id && $to_bare && str_contains($raw_to, '@lid')) {
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
            $this->transport->send_text($send_to, $this->get_reminder_text());
            return new WP_REST_Response(['status' => 'invalid_reply'], 200);
        }

        // ══════════════════════════════════════════════════════════════
        // تسجيل RSVP
        // يعتمد على original_phone أولاً، ثم norm_phone، ثم wa_number
        // (الـ pending القديمة قد لا تحوي original_phone فنرجع لـ wa_number)
        // ══════════════════════════════════════════════════════════════
        $rsvp_phone = !empty($pending['original_phone'])
            ? $pending['original_phone']
            : (!empty($pending['norm_phone'])
                ? $pending['norm_phone']
                : pge_norm_phone($pending['wa_number'] ?? $from_bare));

        $this->log("📋 RSVP phone resolved: original_phone=" . ($pending['original_phone'] ?? 'N/A')
            . " | norm_phone=" . ($pending['norm_phone'] ?? 'N/A')
            . " | wa_number=" . ($pending['wa_number'] ?? 'N/A')
            . " | resolved=$rsvp_phone");

        $rsvp_id = $this->record_rsvp($event_id, $rsvp_phone, $reply);
        if ($rsvp_id < 0) {
            return new WP_REST_Response(['status' => 'integrity_error'], 200);
        }

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

        // بناء سطر الموقع — يُدمج في رسالة التأكيد
        $location_url  = (string) get_post_meta($event_id, '_pge_event_location', true);
        $address_text  = (string) get_post_meta($event_id, '_pge_event_address',  true);
        $location_line    = '';
        $static_map_image = ''; // رابط صورة الخريطة إذا نجح الاستخراج

        if ($reply === 'yes' && $location_url !== '') {
            $maps_api_key = (string) get_option('pge_google_maps_api_key', '');

            if ($maps_api_key !== '' && function_exists('pge_extract_maps_coordinates') && function_exists('pge_build_static_map_url')) {
                // Key موجود — نحاول إرسال صورة الخريطة
                $coords = pge_extract_maps_coordinates($location_url);
                if ($coords) {
                    $static_map_image = pge_build_static_map_url($coords['lat'], $coords['lng']);
                    $this->log("🗺 static map built: lat={$coords['lat']} lng={$coords['lng']}");
                } else {
                    $this->log("⚠️ static map: لم يتم استخراج الإحداثيات، سيُرسل الرابط نصاً");
                }
            }

            // بدون Key أو إذا فشل الاستخراج — ندرج الرابط في رسالة التأكيد
            if ($static_map_image === '') {
                $location_line = "\n\n━━━━━━━━━━━━━━━\n📍 *موقع المناسبة*";
                if ($address_text !== '') {
                    $location_line .= "\n🏛 {$address_text}";
                }
                $location_line .= "\n{$location_url}";
            }
        }

        $tpl_vars = [
            'event_name'    => $event_name,
            'event_url'     => $event_url,
            'invite_code'   => $invite_code,
            'guest_phone'   => $disp_phone,
            'location_line' => $location_line, // للقوالب التي تحتوي المتغير صراحةً
        ];

        $tpl = ($reply === 'yes')
            ? ($tpls['yes'] ?? pge_wa_default_reply_yes_template())
            : ($tpls['no']  ?? pge_wa_default_reply_no_template());

        $confirm_msg = function_exists('pge_wa_render_template')
            ? pge_wa_render_template($tpl, $tpl_vars)
            : $tpl;

        // إذا لم يُدمج الموقع عبر المتغير (قالب مخصص بدون {{location_line}})
        // نُلحقه مباشرةً بنهاية الرسالة — يضمن الوصول دائماً
        if ($location_line !== '' && !str_contains($confirm_msg, $location_url)) {
            $confirm_msg .= $location_line;
        }

        $confirm_result = $this->transport->send_text($send_to, $confirm_msg);
        $confirm_ok = $confirm_result !== null
            && !(isset($confirm_result['status']) && $confirm_result['status'] === 'error')
            && !(isset($confirm_result['success']) && $confirm_result['success'] === false);
        $this->log("📤 confirm msg → $send_to | " . ($confirm_ok ? '✅ sent' : '❌ failed: ' . json_encode($confirm_result)));

        // ── إرسال QR code عند تأكيد الحضور ───────────────────────────────────
        // Phase 9B QR Architecture Final Fix: حمولة صورة QR الآن هي الحمولة
        // الكنسية الموقَّعة (event_id|rsvp_id|qr_version|signature) عبر
        // PGE_Guest_Resolution_Service::build_scanner_qr_payload() — وليست
        // invite_code الخام كما كانت سابقاً (invite_code لم يعد بيانات ماسح
        // الدخول؛ يبقى فقط نصاً مرجعياً بشرياً في نص التسمية التوضيحية أدناه).
        if ($reply === 'yes') {
            // إذا فرغ invite_code من الـ pending، نأخذه من الـ event مباشرة
            // (يُستخدم هنا فقط كنص مرجعي بشري في التسمية التوضيحية، لا كحمولة QR)
            if ($invite_code === '') {
                $raw_code = (string) get_post_meta($event_id, '_pge_invite_code', true);
                if ($raw_code !== '' && function_exists('pge_normalize_invite_code')) {
                    $invite_code = pge_normalize_invite_code($raw_code);
                }
                $this->log($invite_code !== ''
                    ? "ℹ️ QR caption: invite_code من الـ pending فارغ، استُخدم رمز المناسبة: $invite_code"
                    : "ℹ️ QR caption: لا يوجد رمز دعوة مرجعي للمناسبة $event_id"
                );
            }

            if ($rsvp_id > 0 && function_exists('pge_generate_qr_url') && class_exists('PGE_Guest_Resolution_Service')) {
                $scanner_payload = PGE_Guest_Resolution_Service::build_scanner_qr_payload($event_id, $rsvp_id, $rsvp_phone);
                if ($scanner_payload !== '') {
                    $qr_url     = pge_generate_qr_url($scanner_payload);
                    $qr_caption = "🔳 *بطاقة دخولك*\nأرِها عند الباب للدخول السريع"
                        . ($invite_code !== '' ? "\n🔑 الرمز المرجعي: *{$invite_code}*" : '');
                    $qr_result  = $this->transport->send_media($send_to, $qr_url, $qr_caption);
                    $qr_ok = $qr_result !== null
                        && !(isset($qr_result['status']) && $qr_result['status'] === 'error')
                        && !(isset($qr_result['success']) && $qr_result['success'] === false);
                    $this->log("🔳 QR send → $send_to | rsvp_id=$rsvp_id | " . ($qr_ok ? '✅ sent' : '❌ failed: ' . json_encode($qr_result)));
                } else {
                    $this->log("⚠️ QR skipped: تعذّر بناء حمولة الماسح الكنسية لـ rsvp_id=$rsvp_id");
                }
            } else {
                $this->log("⚠️ QR skipped: لا rsvp_id صالح للمناسبة $event_id");
            }

            // إرسال صورة الخريطة كرسالة منفصلة (أكثر وضوحاً من النص)
            if ($static_map_image !== '') {
                $map_caption  = "📍 *موقع المناسبة*";
                if ($address_text !== '') {
                    $map_caption .= "\n🏛 {$address_text}";
                }
                $map_caption .= "\n{$location_url}";

                $map_result = $this->transport->send_media($send_to, $static_map_image, $map_caption);
                $map_ok = $map_result !== null
                    && !(isset($map_result['status']) && $map_result['status'] === 'error')
                    && !(isset($map_result['success']) && $map_result['success'] === false);
                $this->log("🗺 map image send → $send_to | " . ($map_ok ? '✅ sent' : '❌ failed: ' . json_encode($map_result)));
            }
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
        if (!$this->transport->has_credentials()) {
            wp_send_json_error(['message' => 'لم يتم ضبط Cartat API Token']);
        }
        if ($test_phone === '') {
            wp_send_json_error(['message' => 'أدخل رقم الجوال للاختبار']);
        }

        $wa_number  = $this->transport->format_number($test_phone);
        $norm_phone = pge_norm_phone($test_phone);

        $event          = get_post($event_id);
        $event_name     = $event ? $event->post_title : 'مناسبتنا';
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_date     = $event_date_raw
            ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
            : '';
        // بناء رسالة الدعوة التجريبية — عبر PGE_Message_Content_Resolver
        // المشترك (Messaging Architecture Phase 1). نفس القالب، نفس
        // المتغيرات، نفس مصدر الصورة البارزة المُستخدَمة قبل هذا الـRefactor
        // حرفياً — لا تغيير في القيمة الناتجة.
        $guest_context = [
            'guest_name'      => $test_name ?: 'ضيف تجريبي',
            'event_name'      => $event_name,
            'event_date'      => $event_date,
            'event_date_line' => $event_date ? "\n📅 {$event_date}" : '',
            'guest_phone'     => $norm_phone,
        ];

        if (class_exists('PGE_Message_Content_Resolver') && class_exists('PGE_Message_Type')) {
            $content   = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_id, $guest_context);
            $caption   = $content['text'];
            $image_url = $content['image_url'];
        } else {
            // احتياط دفاعي فقط (لا يُتوقَّع تفعيله عملياً — الملف مُحمَّل دوماً
            // من pgevents-core.php) بنفس المنطق القديم حرفياً.
            $image_url = (string) get_the_post_thumbnail_url($event_id, 'full');
            $tpl_invite = function_exists('pge_wa_get_templates')
                ? pge_wa_get_templates($event_id)['invite']
                : pge_wa_default_invite_template();
            $caption = pge_wa_render_template($tpl_invite, $guest_context);
        }

        $result = $image_url
            ? $this->transport->send_media($wa_number, $image_url, $caption)
            : $this->transport->send_text($wa_number, $caption);

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

        if (!$this->transport->has_credentials()) {
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
            $wa_number  = $this->transport->format_number($phone);
            $guest_name = $guests_map[$phone]['name'] ?? 'ضيفنا العزيز';
            $norm_phone = pge_norm_phone($phone);

            // رمز الضيف الشخصي — fallback للرمز الموحّد
            $guest_code_raw    = $guests_map[$phone]['code'] ?? '';
            $guest_invite_code = $guest_code_raw !== ''
                ? (function_exists('pge_normalize_invite_code') ? pge_normalize_invite_code($guest_code_raw) : $guest_code_raw)
                : $event_invite_code;

            // بناء رسالة الدعوة — عبر PGE_Message_Content_Resolver المشترك
            // (Messaging Architecture Phase 1). image_url يُمرَّر صراحةً من
            // القيمة المحسوبة مرة واحدة خارج هذه الحلقة أعلاه (السطر أعلاه
            // في الدالة) — بلا أي استدعاء إضافي لـget_the_post_thumbnail_url()
            // لكل هاتف، بنفس أداء الكود القديم حرفياً.
            if (class_exists('PGE_Message_Content_Resolver') && class_exists('PGE_Message_Type')) {
                $content = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_id, [
                    'guest_name'      => $guest_name,
                    'event_name'      => $event_name,
                    'event_date'      => $event_date,
                    'event_date_line' => $event_date ? "\n📅 {$event_date}" : '',
                    'guest_phone'     => $norm_phone,
                    'image_url'       => $image_url,
                ]);
                $caption          = $content['text'];
                $phone_image_url  = $content['image_url'];
            } else {
                // احتياط دفاعي فقط (لا يُتوقَّع تفعيله عملياً) بنفس المنطق القديم حرفياً.
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
                $phone_image_url = $image_url ?: null;
            }

            // إرسال صورة أو نص حسب توفر الصورة
            if ($phone_image_url) {
                $result = $this->transport->send_media($wa_number, $phone_image_url, $caption);
            } else {
                $result = $this->transport->send_text($wa_number, $caption);
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
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════
    // ملاحظة: send_text_message/send_media_message/api_request/interpret_
    // cartat_result/format_wa_number السابقة هنا انتقلت بالكامل (نفس المنطق
    // حرفياً، بلا أي تغيير) إلى includes/class-pge-cartat-transport.php
    // (Supervisor Invitation Delivery via Cartat — تنفيذ، Option B) — راجع
    // $this->transport أعلى الملف. لا نسخة موازية منها بقيت هنا.

    /**
     * تحليل رد المدعو إلى 'yes' أو 'no'
     */
    private function parse_rsvp_reply(string $body): string
    {
        $b = mb_strtolower(trim($body));

        $yes = ['1', '١', 'نعم', 'yes', 'حاضر', 'سأحضر', 'حضور', 'اوكي', 'موافق', 'ok', '✅', 'ايه', 'اه'];
        $no  = ['2', '٢', 'لا', 'no', 'اعتذر', 'لن احضر', 'اعتذار', 'معذرة', '❌', 'مش قادر', 'مو قادر'];

        if (in_array($b, $yes, true)) return 'yes';
        if (in_array($b, $no,  true)) return 'no';
        return '';
    }

    /**
     * تسجيل RSVP في الجدول المخصص
     *
     * Phase 9B QR Architecture Final Fix: أصبحت تُعيد rsvp_id (كان void) —
     * المستدعي يحتاجه الآن لبناء حمولة QR الكنسية الموقَّعة عبر
     * PGE_Guest_Resolution_Service::build_scanner_qr_payload() بدل invite_code
     * الخام. لا تغيير على أي منطق تخزين/Replacement Entitlement أدناه.
     * يعيد -1 عند integrity_error كي يتوقف الـWebhook قبل مسح pending أو الإرسال.
     */
    private function record_rsvp(int $event_id, string $phone, string $reply): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';
        $phone = pge_norm_phone($phone);

        $lookup = pge_rsvp_find_canonical_by_phone($event_id, $phone);
        if ($lookup['status'] === 'integrity_error') {
            return -1;
        }
        $existing_row = $lookup['status'] === 'found' ? $lookup['row'] : null;

        // RC1 Final Release Blocker: RSVP Write Path Unification — نفس القرار
        // الموحَّد المُستخدَم في rsvp-handler.php/UltraMsg/rsvp-migration.php،
        // بلا أي نسخة موازية من الشرط هنا. قبل هذا التوحيد كانت هذه الدالة
        // تُحدِّث created_at لأي صف موجود بالهاتف بلا أي تحقق من انتمائه لدورة
        // حياة الدعوة الحالية — ما كان يسمح لصف يتيم (من دعوة محذوفة) بأن
        // "يُحيا" فعلياً بمجرد أن يرسل نفس الرقم رداً آخر على واتساب.
        if (class_exists('PGE_Invitation_Repository')) {
            $existing_row = PGE_Invitation_Repository::current_or_null($event_id, $phone, $existing_row);
        }

        $existing_id = $existing_row->id ?? null;
        $old_reply   = $existing_row->reply ?? null;

        if ($existing_id) {
            $wpdb->update(
                $table,
                ['reply' => $reply],
                ['id' => $existing_id]
            );
            $rsvp_id = (int) $existing_id;
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
            $rsvp_id = (int) $wpdb->insert_id;
        }

        // المرحلة 4B: منح Replacement Entitlement عند انتقال RSVP حقيقي إلى
        // اعتذار — بعد نجاح تخزين الرد أعلاه فقط. لا يغيّر شيئاً في السلوك
        // اللاحق لهذه الدالة (المستدعي يواصل بناء رسالة التأكيد وQR/الخريطة
        // كما هو تماماً بعد هذا الاستدعاء) ولا في عقد Webhook Response.
        if ($reply === 'no' && function_exists('pge_maybe_grant_replacement_entitlement')) {
            pge_maybe_grant_replacement_entitlement($event_id, $phone, $old_reply, $reply);
        }

        return $rsvp_id;
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

    /**
     * ============================================================================
     * حساب "الحد الفعّال" لرصيد الدعوات البديلة (Replacement Credits) — المرحلة
     * 4C: Replacement Credit Consumption During Cartat Queue Delivery.
     * ============================================================================
     * effective_limit = MIN(replacement_credit_limit من الـResolver المركزي
     * pge_get_user_plan_limits_for_events()['replacement_credit_total'],
     * عدد الاستحقاقات الممنوحة إجمالاً granted_count عبر
     * PGE_Replacement_Entitlements::count_granted()). لا اعتماد إطلاقاً على
     * أي قيمة يرسلها المتصفح — كل شيء هنا يُعاد حسابه من مصادر الحقيقة
     * الفعلية فقط، بنفس منهج قسم invitation_credit_total الحالي أعلاه تماماً.
     *
     * used_or_reserved = عدد الصفوف reserved "النشطة فعلاً" (عبر
     * PGE_Invitation_Credit_Ledger::count_active_reserved() الجديدة — لا
     * count_reserved() البسيطة؛ راجع توثيقها هناك لسبب هذا الاختيار تحديداً)
     * + عدد الصفوف consumed، كلاهما لـcredit_type='replacement' فقط ضمن نفس
     * (subscriber_user_id, credit_cycle_id).
     *
     * تُستدعى من موقعين مختلفين تماماً بغرضين مختلفين تماماً:
     *  1. ajax_queue_start(): فحص إرشادي مبكر **غير ذري** (بلا أي قفل) —
     *     فقط لرفض بدء Queue ميؤوس منها فوراً (تجربة استخدام أفضل للمضيف)
     *     بدل تركها تفشل لاحقاً بصمت في الـCron. القيمة المُعادة هنا **لا**
     *     تُعتبر نهائية أو ملزمة، ولا تُستخدَم لحجز أي شيء فعلياً.
     *  2. cron_process_queue(): الفحص الذري الفعلي والملزم — يُستدعى **تحت**
     *     قفل PGE_Replacement_Entitlements::build_replacement_credit_lock_name()،
     *     ونتيجته هي التي تقرر فعلياً هل يُستدعى claim_for_delivery() أم لا.
     *
     * دالة قراءة بحتة بالكامل — لا تعديل أو كتابة من هنا إطلاقاً.
     */
    private function compute_replacement_effective_limit(int $subscriber_user_id, string $credit_cycle_id): array
    {
        if ($subscriber_user_id <= 0 || $credit_cycle_id === '') {
            return [
                'package_limit'    => 0,
                'granted_count'    => 0,
                'reserved_active'  => 0,
                'consumed_count'   => 0,
                'used_or_reserved' => 0,
                'effective_limit'  => 0,
                'available'        => false,
                'reason'           => 'no_available_entitlement',
            ];
        }

        $resolved_limits   = function_exists('pge_get_user_plan_limits_for_events')
            ? (array) pge_get_user_plan_limits_for_events($subscriber_user_id)
            : [];
        $raw_package_limit = $resolved_limits['replacement_credit_total'] ?? null;
        $package_limit     = (is_int($raw_package_limit) && $raw_package_limit >= 0) ? $raw_package_limit : 0;

        $granted_count = class_exists('PGE_Replacement_Entitlements')
            ? (int) PGE_Replacement_Entitlements::count_granted($subscriber_user_id, $credit_cycle_id)
            : 0;

        $reserved_active = class_exists('PGE_Invitation_Credit_Ledger')
            ? (int) PGE_Invitation_Credit_Ledger::count_active_reserved($subscriber_user_id, $credit_cycle_id, 'replacement')
            : 0;

        $consumed_count = class_exists('PGE_Invitation_Credit_Ledger')
            ? (int) PGE_Invitation_Credit_Ledger::count_consumed($subscriber_user_id, $credit_cycle_id, 'replacement')
            : 0;

        $effective_limit  = min($package_limit, $granted_count);
        $used_or_reserved = $reserved_active + $consumed_count;
        $available        = $used_or_reserved < $effective_limit;

        // سبب الرفض عند عدم التوفر: إن كان granted_count هو القيد المُلزِم
        // (مساوٍ أو أصغر من package_limit) فالسبب "لا استحقاق متاح"؛ وإلا
        // فالسبب "بلوغ حد الباقة نفسه".
        $reason = ($granted_count <= $package_limit) ? 'no_available_entitlement' : 'limit_exceeded';

        return [
            'package_limit'    => $package_limit,
            'granted_count'    => $granted_count,
            'reserved_active'  => $reserved_active,
            'consumed_count'   => $consumed_count,
            'used_or_reserved' => $used_or_reserved,
            'effective_limit'  => $effective_limit,
            'available'        => $available,
            'reason'           => $available ? null : $reason,
        ];
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
        if (!$this->transport->has_credentials()) {
            wp_send_json_error(['message' => 'لم يتم ضبط Cartat API Token']);
        }

        // حماية تكرار Queue (المرحلة الثالثة A، القسم حادي عشر): إن كانت
        // هناك Queue سابقة لنفس المناسبة لا تزال queued أو running، لا تُنشئ
        // Queue ثانية — هذا فحص خادمي مستقل عن تعطيل الزر في JavaScript (قد
        // يُتجاوَز بضغطتين سريعتين قبل استلام أول استجابة AJAX).
        $existing_queue = get_option($this->queue_key($event_id));
        if (is_array($existing_queue) && in_array($existing_queue['status'] ?? '', ['queued', 'running'], true)) {
            wp_send_json_error(['message' => '⏳ يوجد إرسال جارٍ بالفعل لهذه المناسبة. انتظر اكتماله أو راجع التقرير.']);
        }

        $phones = pge_get_invited_phones($event_id);
        if (empty($phones)) {
            wp_send_json_error(['message' => 'لا يوجد مدعوون']);
        }

        // ══════════════════════════════════════════════════════════════
        // عقد credit_type الخادمي الصريح (المرحلة 4C)
        // ══════════════════════════════════════════════════════════════
        // القيمة الافتراضية عند غياب الحقل أو فراغه: 'primary' (السلوك
        // الحالي تماماً، بلا أي تغيير). أي قيمة أخرى غير 'primary'/'replacement'
        // بالضبط تُرفَض صراحةً — لا Queue يُنشأ، لا حجز Ledger من أي نوع. لا
        // ثقة إطلاقاً بأي حد/دورة/entitlement_id يرسله المتصفح — هذه القيم
        // كلها تُعاد حسابها من مصادر الخادم فقط أدناه بصرف النظر عمّا وصل
        // ضمن $_POST.
        $credit_type_raw = isset($_POST['credit_type']) ? sanitize_text_field(wp_unslash($_POST['credit_type'])) : '';
        $credit_type      = ($credit_type_raw === '') ? 'primary' : $credit_type_raw;
        if (!in_array($credit_type, ['primary', 'replacement'], true)) {
            wp_send_json_error(['message' => '⛔ نوع رصيد غير صالح.']);
        }

        // ══════════════════════════════════════════════════════════════
        // Invitation Credits Engine — صاحب الاشتراك الحقيقي (المرحلة الثالثة A)
        // ══════════════════════════════════════════════════════════════
        // صاحب الاشتراك الذي سيُخصَم رصيده هو مالك المناسبة (post_author)،
        // وليس get_current_user_id() — لأن مسؤولاً (administrator) قد يبدأ
        // الإرسال نيابةً عن مضيف آخر (pge_is_host_or_admin() تسمح بذلك أعلاه)،
        // وcron_process_queue() لاحقاً يعمل عبر WP-Cron بلا أي مستخدم مسجَّل
        // دخول إطلاقاً (get_current_user_id() = 0 هناك). يُلتَقط مرة واحدة
        // هنا فقط ويُخزَّن ضمن $queue — لا إعادة استنتاج لاحقاً.
        $subscriber_user_id = (int) get_post_field('post_author', $event_id);

        $is_catalog             = false;
        $credit_cycle_id        = '';
        $invitation_credit_total = 0;

        if ($subscriber_user_id > 0) {
            $package_source = (string) get_user_meta($subscriber_user_id, '_mon_package_source', true);

            if ($package_source === 'catalog') {
                // Catalog — يجب أن يكون الاشتراك نشطاً فعلياً، ودورة الرصيد
                // موجودة، وحد الرصيد رقماً صحيحاً غير سالب. لا اعتماد على قيمة
                // Tier الحية إطلاقاً — فقط Snapshot المستخدم والـResolver
                // المركزي (pge_get_user_plan_limits_for_events)، تماماً كما
                // تقرأ activate_catalog_tier()/الـResolver نفسيهما.
                $package_status = (string) get_user_meta($subscriber_user_id, '_mon_package_status', true);
                if ($package_status !== 'active') {
                    wp_send_json_error(['message' => '⛔ لا يمكن بدء الإرسال: اشتراك Catalog لصاحب المناسبة غير نشط (منتهٍ أو مُلغى).']);
                }

                $credit_cycle_id = (string) get_user_meta($subscriber_user_id, '_mon_credit_cycle_id', true);
                if ($credit_cycle_id === '') {
                    wp_send_json_error(['message' => '⛔ لا يمكن بدء الإرسال: لا توجد دورة رصيد صالحة لاشتراك صاحب المناسبة.']);
                }

                if ($credit_type === 'primary') {
                    // === السلوك الحالي، بلا أي تغيير إطلاقاً (المرحلة الثالثة A) ===
                    $resolved_limits = function_exists('pge_get_user_plan_limits_for_events')
                        ? (array) pge_get_user_plan_limits_for_events($subscriber_user_id)
                        : [];
                    $raw_total = $resolved_limits['invitation_credit_total'] ?? null;

                    if (!is_int($raw_total) || $raw_total < 0) {
                        wp_send_json_error(['message' => '⛔ لا يمكن بدء الإرسال: تعذّر تحديد رصيد الدعوات المتاح لصاحب المناسبة.']);
                    }

                    $invitation_credit_total = $raw_total;
                    $is_catalog = true;
                } else {
                    // credit_type === 'replacement' (المرحلة 4C) — فحص إرشادي
                    // مبكر غير ذري (راجع توثيق compute_replacement_effective_limit()):
                    // رفض فوري وواضح للمضيف بدل ترك Queue تُنشأ لتفشل بصمت
                    // لاحقاً في كل دفعة Cron. القيمة الفعلية الملزمة تُعاد
                    // حسابها ذرياً من جديد داخل cron_process_queue() تحت قفل
                    // مخصص — هذا الفحص هنا للتجربة/الرفض المبكر فقط.
                    $rc = $this->compute_replacement_effective_limit($subscriber_user_id, $credit_cycle_id);

                    if ($rc['package_limit'] <= 0) {
                        wp_send_json_error(['message' => '⛔ لا يوجد رصيد دعوات بديلة (Replacement) ضمن باقة صاحب المناسبة.']);
                    }

                    if (!$rc['available']) {
                        $msg = ($rc['reason'] === 'no_available_entitlement')
                            ? '⛔ لا يوجد استحقاق دعوة بديلة متاح حالياً لصاحب المناسبة (لم يعتذر أحد بعد إرسال دعوة أساسية).'
                            : '⛔ تم بلوغ الحد الأقصى لرصيد الدعوات البديلة ضمن الباقة.';
                        wp_send_json_error(['message' => $msg]);
                    }

                    // Snapshot إرشادي فقط عند بدء الـQueue — القيمة الملزمة
                    // النهائية تُعاد حسابها من جديد داخل cron_process_queue()
                    // (راجع القسم 3 من مواصفة المرحلة 4C: "أعد الحساب"، لا
                    // تثق بقيمة قديمة قد تغيّرت بين بدء الـQueue وتنفيذ الـCron
                    // الفعلي — مثلاً استحقاق جديد مُنح، أو استحقاقات أخرى
                    // استُهلكت من مناسبة أخرى لنفس المستخدم بالتزامن).
                    $invitation_credit_total = $rc['effective_limit'];
                    $is_catalog = true;
                }
            } elseif ($credit_type === 'replacement') {
                // مستخدم بلا اشتراك Catalog (Legacy أو بلا اشتراك مسجَّل) —
                // مفهوم Replacement Credits غير موجود إطلاقاً خارج نظام
                // الباقات (Catalog)؛ رفض صريح، لا Queue، لا Ledger.
                wp_send_json_error(['message' => '⛔ رصيد الدعوات البديلة متاح فقط لمستخدمي نظام الباقات (Catalog).']);
            }
            // أي قيمة أخرى (بما فيها الفراغ) = Legacy أو مستخدم بلا اشتراك
            // مسجَّل بعد، مع credit_type=primary — يستمر بالسلوك الحالي
            // تماماً، بلا Ledger وبلا أي منع جديد (خارج نطاق هذه المرحلة صراحةً).
        } elseif ($credit_type === 'replacement') {
            // لا مالك صالح للمناسبة أصلاً — لا معنى لأي رصيد Replacement.
            wp_send_json_error(['message' => '⛔ تعذّر تحديد صاحب المناسبة.']);
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
            'cancel_reason' => null,

            // Invitation Credits Engine (المرحلة الثالثة A) — راجع أعلاه.
            // is_catalog=false يعني Legacy: لا Ledger ولا خصم إطلاقاً في
            // cron_process_queue() لهذه الـQueue بصرف النظر عن بقية الحقول.
            'is_catalog'                => $is_catalog,
            'subscriber_user_id'        => $subscriber_user_id,
            'credit_cycle_id'           => $credit_cycle_id,
            'credit_type'               => $credit_type,
            'invitation_credit_total'   => $invitation_credit_total,
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
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error(['message' => 'Missing event_id']);
        }
        if (!pge_is_host_or_admin($event_id)) {
            wp_send_json_error(['message' => 'Unauthorized']);
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

    /**
     * مزامنة _mon_invitation_credit_used من Ledger مباشرة (القسم تاسعاً،
     * المرحلة الثالثة A) — لا قراءة used ثم used+1 (عرضة لفقد تحديثات
     * متزامنة)، بل احتساب count_consumed() الفعلي وكتابته كاملاً. Ledger
     * يبقى مصدر الحقيقة دائماً بصرف النظر عن نجاح هذه المزامنة أو فشلها.
     */
    private function sync_invitation_credit_used(int $subscriber_user_id, string $credit_cycle_id, string $credit_type): void
    {
        if ($subscriber_user_id <= 0 || $credit_cycle_id === '') {
            return;
        }

        // تحقّق إضافي: لا تكتب على دورة لم تعد سارية (قد تكون تغيّرت بين
        // claim/mark_consumed وهذه اللحظة بالذات) — الحارس في بداية
        // cron_process_queue() يمنع أغلب الحالات، وهذا احتياط أخير.
        $current_cycle = (string) get_user_meta($subscriber_user_id, '_mon_credit_cycle_id', true);
        if ($current_cycle === '' || $current_cycle !== $credit_cycle_id) {
            return;
        }

        $actual_count = PGE_Invitation_Credit_Ledger::count_consumed($subscriber_user_id, $credit_cycle_id, $credit_type);
        update_user_meta($subscriber_user_id, '_mon_invitation_credit_used', $actual_count);

        // update_user_meta() في ووردبريس تُعيد false أيضاً حين لا تتغيّر
        // القيمة (لا فرق بينها وبين فشل حقيقي في قيمة الإرجاع وحدها) — لذا
        // التحقّق الموثوق الوحيد هو إعادة القراءة والمقارنة، لا فحص القيمة
        // المُعادة من update_user_meta() نفسها.
        $verify = (int) get_user_meta($subscriber_user_id, '_mon_invitation_credit_used', true);
        if ($verify !== $actual_count) {
            $this->log("⚠️ synchronization_error: تعذّر تحديث _mon_invitation_credit_used فعلياً | user=$subscriber_user_id | expected=$actual_count | actual=$verify (Ledger يبقى مصدر الحقيقة)");
        }
    }

    /**
     * ============================================================================
     * استهلاك استحقاق Replacement وربطه بصف Ledger مُستهلَك فعلياً (المرحلة
     * 4C، القسمان 4-5) — تُستدعى **فقط** بعد نجاح mark_consumed_with_token()
     * على صف replacement فعلي، لا قبل ذلك إطلاقاً.
     * ============================================================================
     * غلاف رقيق حول PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger()
     * + التسجيل + مزامنة العدّاد. لا تُعيد أي قيمة تؤثر على $queue['results'] —
     * نجاح Cartat/الـLedger مكتمل ونهائي بصرف النظر عن نتيجة هذه الدالة (راجع
     * القسم 10 من المواصفة): فشل الربط هنا يُسجَّل كـ
     * replacement_entitlement_link_error ويُترَك لـ
     * reconcile_consumed_replacement_ledger() لاحقاً — لا يُعاد أي شيء إلى
     * الوراء في Cartat أو الـLedger أبداً.
     *
     * مُغلَّفة بالكامل بـtry/catch(\Throwable) دفاعياً — أي استثناء غير
     * متوقع هنا يُسجَّل فقط ولا يُعاد رميه أبداً، بنفس فلسفة
     * pge_maybe_grant_replacement_entitlement() في المرحلة 4B تماماً (لا
     * يجوز لأي خطأ في هذه الطبقة الثانوية أن يُسقِط دفعة Cron كاملة).
     */
    private function consume_replacement_entitlement_for_ledger(int $subscriber_user_id, string $credit_cycle_id, int $ledger_id, int $event_id, string $phone): void
    {
        try {
            if (!class_exists('PGE_Replacement_Entitlements')) {
                $this->log("❌ replacement_entitlement_link_error: PGE_Replacement_Entitlements غير محمَّلة | ledger_id=$ledger_id | event=$event_id | phone=$phone");
                return;
            }

            $result  = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($subscriber_user_id, $credit_cycle_id, $ledger_id);
            $outcome = $result['result'] ?? 'error';

            if ($outcome === 'consumed') {
                $this->log("✅ replacement_entitlement_consumed: entitlement_id={$result['id']} | ledger_id=$ledger_id | user=$subscriber_user_id | cycle=$credit_cycle_id | event=$event_id | phone=$phone");
                $this->sync_replacement_credit_used($subscriber_user_id, $credit_cycle_id);
                return;
            }

            if ($outcome === 'already_linked') {
                $this->log("ℹ️ replacement_entitlement_already_linked: entitlement_id={$result['id']} | ledger_id=$ledger_id | user=$subscriber_user_id | cycle=$credit_cycle_id");
                $this->sync_replacement_credit_used($subscriber_user_id, $credit_cycle_id);
                return;
            }

            // 'error' — فشل ربط حرج (القسم 10 من المواصفة): Cartat نجح
            // والـLedger consumed فعلاً، لكن تعذّر ربط/استهلاك أي استحقاق. لا
            // نعكس أي شيء هنا — reconcile_consumed_replacement_ledger($ledger_id)
            // قابلة لإعادة التشغيل لاحقاً لإصلاح هذه الحالة بالذات يدوياً.
            $reason = $result['reason'] ?? 'unknown';
            $this->log("❌ replacement_entitlement_link_error: ledger_id=$ledger_id | user=$subscriber_user_id | cycle=$credit_cycle_id | event=$event_id | phone=$phone | reason=$reason");
        } catch (\Throwable $e) {
            $this->log("❌ replacement_entitlement_link_error: استثناء غير متوقع | ledger_id=$ledger_id | user=$subscriber_user_id | " . $e->getMessage());
        }
    }

    /**
     * مزامنة _mon_replacement_credit_used من Ledger/Entitlements مباشرة
     * (المرحلة 4C، القسم 6) — دالة **مستقلة تماماً** عن
     * sync_invitation_credit_used() الحالية أعلاه؛ لا يجوز إعادة استخدام
     * تلك الدالة هنا: تكتب دائماً على _mon_invitation_credit_used حصراً بصرف
     * النظر عن credit_type المُمرَّر إليها، وهو مفتاح User Meta خاطئ تماماً
     * لعدّاد Replacement (تأكّدنا من هذا بالتدقيق قبل الكتابة — راجع تعليق
     * update_user_meta() هناك).
     *
     * COUNT حقيقي دائماً عبر PGE_Replacement_Entitlements::count_consumed()
     * (عدد الاستحقاقات المُستهلَكة فعلياً — لا Ledger::count_consumed() على
     * صفوف replacement مباشرة؛ العدّاد يعكس عمداً استحقاقات مربوطة فعلياً لا
     * صفوف Ledger مستهلكة قد تنتظر ربطاً بعد عبر reconcile) — لا زيادة/نقصان
     * تراكمي إطلاقاً، بنفس فلسفة sync_invitation_credit_used() تماماً. لا
     * تلمس _mon_replacement_credit_total مطلقاً — ذلك حد الباقة الأقصى
     * (Snapshot عند التفعيل)، مستقل كلياً عن هذه الدالة.
     */
    private function sync_replacement_credit_used(int $subscriber_user_id, string $credit_cycle_id): void
    {
        if ($subscriber_user_id <= 0 || $credit_cycle_id === '') {
            return;
        }

        // نفس احتياط sync_invitation_credit_used(): لا كتابة على دورة لم
        // تعد سارية (قد تكون تغيّرت بين claim/mark_consumed وهذه اللحظة
        // بالذات).
        $current_cycle = (string) get_user_meta($subscriber_user_id, '_mon_credit_cycle_id', true);
        if ($current_cycle === '' || $current_cycle !== $credit_cycle_id) {
            return;
        }

        if (!class_exists('PGE_Replacement_Entitlements')) {
            return;
        }

        $actual_count = PGE_Replacement_Entitlements::count_consumed($subscriber_user_id, $credit_cycle_id);
        update_user_meta($subscriber_user_id, '_mon_replacement_credit_used', $actual_count);

        $verify = (int) get_user_meta($subscriber_user_id, '_mon_replacement_credit_used', true);
        if ($verify !== $actual_count) {
            $this->log("⚠️ replacement_credit_used_sync_error: تعذّر تحديث _mon_replacement_credit_used فعلياً | user=$subscriber_user_id | expected=$actual_count | actual=$verify (Entitlements يبقى مصدر الحقيقة)");
            return;
        }

        $this->log("✅ replacement_credit_used_synced: user=$subscriber_user_id | cycle=$credit_cycle_id | used=$actual_count");
    }

    /**
     * ============================================================================
     * إصلاح ربط استحقاق Replacement لصف Ledger مُستهلَك فعلياً — قابلة
     * لإعادة التشغيل، تُستدعى يدوياً أو لاحقاً (المرحلة 4C، القسم 10؛ لا
     * Cron دوري مطلوب في هذه المرحلة).
     * ============================================================================
     * تُعالِج بالتحديد حالة "Cartat نجح + الـLedger consumed فعلاً، لكن تعذّر
     * ربط/استهلاك أي Entitlement وقتها" (سُجِّلت كـ
     * replacement_entitlement_link_error وقت حدوثها في
     * consume_replacement_entitlement_for_ledger() أعلاه). Idempotent بالكامل
     * عبر PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger()
     * نفسها — استدعاء متكرر بنفس $ledger_id بعد نجاح سابق يُعيد
     * 'already_linked' بأمان دون أي تأثير إضافي، فيصلح استدعاء هذه الدالة
     * أكثر من مرة على نفس $ledger_id بلا أي خطر.
     *
     * public عمداً: أداة تشغيلية مستقلة (WP-CLI مستقبلاً، أو زر لوحة تحكم
     * لاحقاً — لا شيء من هذا مطلوب في هذه المرحلة) لا تتطلب سياق Queue/Cron
     * حي لتُستدعى.
     *
     * القيم المُعادة:
     *  - ['result'=>'reconciled','entitlement_id'=>int]: ربط جديد نجح الآن.
     *  - ['result'=>'already_linked','entitlement_id'=>int]: كان مربوطاً
     *    فعلاً مسبقاً (لا شيء لإصلاحه).
     *  - ['result'=>'error','reason'=>string]: صف Ledger غير موجود/ليس
     *    replacement/ليس consumed (رفض صريح — لا يجوز "إصلاح" صف لم يُستهلَك
     *    فعلاً أصلاً)، أو لا استحقاق granted متاح حتى الآن
     *    (no_entitlement_available)، أو الكلاسات المطلوبة غير محمَّلة.
     */
    public function reconcile_consumed_replacement_ledger(int $ledger_id): array
    {
        if ($ledger_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_ledger_id'];
        }

        if (!class_exists('PGE_Invitation_Credit_Ledger') || !class_exists('PGE_Replacement_Entitlements')) {
            return ['result' => 'error', 'reason' => 'required_classes_missing'];
        }

        global $wpdb;
        $ledger_table = PGE_Invitation_Credit_Ledger::table_name();
        $ledger_row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $ledger_table WHERE id = %d LIMIT 1", $ledger_id),
            ARRAY_A
        );

        if (!$ledger_row) {
            return ['result' => 'error', 'reason' => 'ledger_not_found'];
        }

        if ((string) ($ledger_row['credit_type'] ?? '') !== 'replacement') {
            return ['result' => 'error', 'reason' => 'not_replacement'];
        }

        if ((string) ($ledger_row['status'] ?? '') !== 'consumed') {
            return ['result' => 'error', 'reason' => 'not_consumed'];
        }

        $user_id         = (int) ($ledger_row['user_id'] ?? 0);
        $credit_cycle_id = (string) ($ledger_row['credit_cycle_id'] ?? '');

        $result  = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_id, $credit_cycle_id, $ledger_id);
        $outcome = $result['result'] ?? 'error';

        if ($outcome === 'consumed') {
            $this->sync_replacement_credit_used($user_id, $credit_cycle_id);
            $this->log("✅ replacement_reconciliation_success: ledger_id=$ledger_id | entitlement_id={$result['id']} | user=$user_id | cycle=$credit_cycle_id");
            return ['result' => 'reconciled', 'entitlement_id' => (int) $result['id']];
        }

        if ($outcome === 'already_linked') {
            $this->log("ℹ️ replacement_reconciliation_success: ledger_id=$ledger_id | already_linked | entitlement_id={$result['id']}");
            return ['result' => 'already_linked', 'entitlement_id' => (int) $result['id']];
        }

        $reason = $result['reason'] ?? 'unknown';
        $this->log("❌ replacement_reconciliation_error: ledger_id=$ledger_id | user=$user_id | cycle=$credit_cycle_id | reason=$reason");
        return ['result' => 'error', 'reason' => $reason];
    }

    /** WP Cron — معالجة دفعة واحدة في الخلفية */
    public function cron_process_queue(int $event_id): void
    {
        $queue = get_option($this->queue_key($event_id));
        if (!$queue || in_array($queue['status'] ?? '', ['done', 'cancelled'], true)) return;

        @set_time_limit(120);

        // ══════════════════════════════════════════════════════════════
        // قفل Queue على مستوى المناسبة (القسم حادي عشر، المرحلة الثالثة A)
        // ══════════════════════════════════════════════════════════════
        // يمنع تشغيل دفعتين من نفس الـQueue في نفس اللحظة (تراكم أحداث
        // WP Cron، أو استدعاء يدوي متزامن). اسم منفصل تماماً عن أقفال
        // الرصيد في PGE_Invitation_Credit_Ledger (نطاق مختلف كلياً). timeout
        // = 0: محاولة واحدة فورية بلا انتظار — إن كانت دفعة أخرى تعمل بالفعل
        // نتخطّى هذه التشغيلة بالكامل بدل الانتظار (ستُعالَج الدفعة التالية
        // المجدولة بعد 35 ثانية بشكل طبيعي).
        global $wpdb;
        $cron_lock_name = 'pge_wa_cron_' . md5((string) $event_id);
        $got_cron_lock  = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $cron_lock_name, 0));
        if ((int) $got_cron_lock !== 1) {
            $this->log("⏭ Queue: تشغيلة أخرى تعمل بالفعل لـ event=$event_id — تخطّي هذه الدفعة");
            return;
        }

        try {
            // إعادة قراءة الـQueue تحت حماية القفل — قد تكون تغيّرت بين
            // القراءة الأولى أعلاه ولحظة الحصول على القفل فعلياً.
            $queue = get_option($this->queue_key($event_id));
            if (!$queue || in_array($queue['status'] ?? '', ['done', 'cancelled'], true)) return;

            $is_catalog         = !empty($queue['is_catalog']);
            $subscriber_user_id = (int) ($queue['subscriber_user_id'] ?? 0);
            $credit_cycle_id    = (string) ($queue['credit_cycle_id'] ?? '');
            $credit_type        = (string) ($queue['credit_type'] ?? 'primary');
            $credit_limit       = (int) ($queue['invitation_credit_total'] ?? 0);

            // ══════════════════════════════════════════════════════════
            // حماية تغيّر دورة الاشتراك (القسم سابعاً) — فحص إلزامي قبل كل دفعة
            // ══════════════════════════════════════════════════════════
            if ($is_catalog) {
                $current_cycle_id = (string) get_user_meta($subscriber_user_id, '_mon_credit_cycle_id', true);
                if ($current_cycle_id === '' || $current_cycle_id !== $credit_cycle_id) {
                    $queue['status']        = 'cancelled';
                    $queue['cancel_reason'] = 'credit_cycle_changed';
                    $queue['done_at']       = current_time('mysql');
                    update_option($this->queue_key($event_id), $queue, false);
                    $this->log("🚫 Queue cancelled: event=$event_id | reason=credit_cycle_changed | queue_cycle=$credit_cycle_id | current_cycle=$current_cycle_id");
                    return;
                }
            }

            $queue['status'] = 'running';
            update_option($this->queue_key($event_id), $queue, false);

            $batch_size = 10; // دفعة صغيرة لضمان عدم انتهاء الوقت
            $phones     = array_slice($queue['phones'], $queue['offset'], $batch_size);

            $limit_reached_early = false;

            foreach ($phones as $phone) {
                if ($limit_reached_early) {
                    $queue['results'][$phone] = ['status' => 'skipped_limit_exceeded', 'time' => current_time('mysql')];
                    continue;
                }

                // ══════════════════════════════════════════════════════
                // Invitation Credits Engine — claim قبل أي استدعاء لكارتات
                // ══════════════════════════════════════════════════════
                $claim = null;
                if ($is_catalog && $credit_type === 'replacement') {
                    // ══════════════════════════════════════════════════
                    // فحص مسبق: هل يوجد صف Ledger سابق لهذا المدعو بالذات،
                    // وما status ه الفعلي؟ (إصلاح Blocker مُبلَّغ صراحةً: لا
                    // يجوز اعتماد قاعدة "أي صف موجود يتجاوز بوابة التوفر" —
                    // فحصت هذا فعلياً بناءً على تنبيه المستخدم ووجدت السيناريو
                    // التالي حقيقياً: Entitlement واحد → A يفشل (Ledger A
                    // يصبح failed، الاستحقاق يبقى granted) → B ينجح ويستهلك
                    // الاستحقاق الوحيد → إعادة محاولة A كانت (قبل هذا الإصلاح)
                    // تتجاوز البوابة لمجرّد وجود صف Ledger سابق لها، فتنجح
                    // وتستهلك Cartat فعلياً بلا أي استحقاق متبقٍّ لربطه —
                    // Overbooking فعلي (2 صفوف replacement مُستهلَكة مقابل
                    // استحقاق واحد فقط). السبب الجذري: status='failed' لا
                    // يمثّل حجزاً نشطاً (غير مُستثنى من count_reserved_or_
                    // consumed_unsafe() أصلاً) ولا استهلاكاً فعلياً — فلا
                    // مبرّر لإعفائه من بوابة التوفر كما يُعفى consumed/reserved
                    // بحق. القاعدة الصحيحة إذاً حسب status الفعلي للصف
                    // الموجود (إن وُجد):
                    //  - consumed: يمرّ مباشرة إلى claim_for_delivery() (تُعيد
                    //    already_consumed) — بلا حجز جديد، بلا بوابة توفر.
                    //  - reserved (Lease صالح أو منتهياً على حدٍّ سواء): يمرّ
                    //    مباشرة إلى claim_for_delivery() (تُعيد in_progress
                    //    أو تستعيد نفس الصف بتوكن جديد عند انتهاء المهلة) —
                    //    بلا بوابة توفر؛ إعادة الاستعادة لا تُنشئ صفاً جديداً
                    //    ولا تحتاج استحقاقاً إضافياً.
                    //  - failed، أو لا صف إطلاقاً: **يجب** المرور عبر بوابة
                    //    التوفر الذرية أدناه أولاً (نفس معاملة "مطالبة جديدة"
                    //    تماماً) — لأن failed لا يحجز أي سعة فعلية حالياً، وقد
                    //    تكون السعة نفدت لصالح مطالبة أخرى منذ الفشل.
                    // ══════════════════════════════════════════════════
                    $existing_entry = class_exists('PGE_Invitation_Credit_Ledger')
                        ? PGE_Invitation_Credit_Ledger::find_entry($credit_cycle_id, $event_id, $phone, 'replacement')
                        : null;
                    $existing_status = $existing_entry !== null ? (string) ($existing_entry['status'] ?? '') : '';
                    $bypasses_availability_gate = in_array($existing_status, ['consumed', 'reserved'], true);

                    if ($bypasses_availability_gate) {
                        $claim = PGE_Invitation_Credit_Ledger::claim_for_delivery(
                            $subscriber_user_id,
                            $credit_cycle_id,
                            $event_id,
                            $phone,
                            $credit_type,
                            0 // غير مُستخدَم: صف consumed/reserved، فرع "لا صف" وحده يقرأ الحد
                        );
                    } else {
                        // ══════════════════════════════════════════════
                        // حجز ذري لمطالبة replacement **جديدة فعلاً، أو إعادة
                        // محاولة صف failed** (المرحلة 4C، القسم 3 + إصلاح
                        // Blocker) — قفل مخصص لمنع Overbooking عبر مناسبات
                        // مختلفة لنفس (user_id, credit_cycle_id) تعمل
                        // بالتوازي، **ويمنع أيضاً سباق "إعادة محاولة failed"
                        // مقابل "مطالبة جديدة أخرى"**: كلا المسارين يمرّان
                        // الآن عبر نفس هذا القفل بالذات (لا فرق بينهما في
                        // الكود من هذه النقطة فصاعداً)، فيُسلسلهما GET_LOCK
                        // تلقائياً بصرف النظر عن أيهما وصل أولاً. قفل
                        // pge_credit_ الداخلي في claim_for_delivery() وحده
                        // غير كافٍ هنا: effective_limit نفسه مُشتق من عدّ
                        // خارجي (Entitlements الممنوحة) يجب حسابه وتثبيته
                        // ذرياً قبل أي claim_for_delivery، لا فقط حماية
                        // الحجز نفسه.
                        // ══════════════════════════════════════════════
                        $rep_lock_name = class_exists('PGE_Replacement_Entitlements')
                            ? PGE_Replacement_Entitlements::build_replacement_credit_lock_name($subscriber_user_id, $credit_cycle_id)
                            : '';
                        $rep_got_lock = ($rep_lock_name !== '')
                            ? (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $rep_lock_name, 5))
                            : 0;

                        if ($rep_got_lock !== 1) {
                            $queue['results'][$phone] = ['status' => 'ledger_error', 'time' => current_time('mysql')];
                            $this->log("⚠️ replacement_claim: تعذّر الحصول على قفل الرصيد البديل | event=$event_id | phone=$phone | user=$subscriber_user_id | cycle=$credit_cycle_id");
                            continue;
                        }

                        try {
                            $rc = $this->compute_replacement_effective_limit($subscriber_user_id, $credit_cycle_id);

                            if (!$rc['available']) {
                                $status    = ($rc['reason'] === 'no_available_entitlement') ? 'skipped_no_entitlement' : 'skipped_limit_exceeded';
                                $log_event = ($rc['reason'] === 'no_available_entitlement') ? 'replacement_claim_no_entitlement' : 'replacement_claim_limit_exceeded';
                                $queue['results'][$phone] = ['status' => $status, 'time' => current_time('mysql')];
                                $this->log("🛑 $log_event: event=$event_id | phone=$phone | user=$subscriber_user_id | cycle=$credit_cycle_id | package_limit={$rc['package_limit']} | granted={$rc['granted_count']} | used_or_reserved={$rc['used_or_reserved']}");
                                $limit_reached_early = true;
                                continue; // finally أدناه يُحرِّر القفل قبل الانتقال فعلياً
                            }

                            $claim = class_exists('PGE_Invitation_Credit_Ledger')
                                ? PGE_Invitation_Credit_Ledger::claim_for_delivery(
                                    $subscriber_user_id,
                                    $credit_cycle_id,
                                    $event_id,
                                    $phone,
                                    $credit_type,
                                    $rc['effective_limit']
                                )
                                : ['result' => 'error', 'reason' => 'ledger_class_missing'];
                        } finally {
                            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $rep_lock_name));
                        }
                    }

                    $claim_result = $claim['result'] ?? 'error';

                    if ($claim_result === 'claimed') {
                        $this->log("✅ replacement_claim_created: event=$event_id | phone=$phone | ledger_id={$claim['id']} | user=$subscriber_user_id | cycle=$credit_cycle_id");
                    } elseif ($claim_result === 'already_consumed') {
                        $this->log("ℹ️ replacement_claim_already_consumed: event=$event_id | phone=$phone | ledger_id={$claim['id']}");
                    } elseif ($claim_result === 'in_progress') {
                        $this->log("⏳ replacement_claim_in_progress: event=$event_id | phone=$phone | ledger_id={$claim['id']}");
                    }

                    if ($claim_result === 'already_consumed') {
                        $queue['results'][$phone] = ['status' => 'skipped_already_consumed', 'time' => current_time('mysql')];
                        continue;
                    }
                    if ($claim_result === 'in_progress') {
                        $queue['results'][$phone] = ['status' => 'skipped_in_progress', 'time' => current_time('mysql')];
                        continue;
                    }
                    if ($claim_result === 'limit_exceeded') {
                        // احتياط نظري فقط: القفل أعلاه يجعل هذا شبه مستحيل عملياً
                        // (effective_limit أُعيد حسابه وثُبِّت تحت نفس القفل قبل
                        // claim_for_delivery مباشرة) — يُعامَل بنفس منطق أعلاه.
                        $queue['results'][$phone] = ['status' => 'skipped_limit_exceeded', 'time' => current_time('mysql')];
                        $this->log("🛑 replacement_claim_limit_exceeded: event=$event_id | phone=$phone (داخل claim_for_delivery نفسها رغم إعادة الحساب)");
                        $limit_reached_early = true;
                        continue;
                    }
                    if ($claim_result === 'error') {
                        $queue['results'][$phone] = ['status' => 'ledger_error', 'time' => current_time('mysql')];
                        $this->log("⚠️ Ledger claim error (replacement): event=$event_id | phone=$phone | reason=" . ($claim['reason'] ?? 'unknown'));
                        continue;
                    }
                    // 'claimed' فقط تتابع إلى الإرسال الفعلي أدناه.
                } elseif ($is_catalog) {
                    $claim = class_exists('PGE_Invitation_Credit_Ledger')
                        ? PGE_Invitation_Credit_Ledger::claim_for_delivery(
                            $subscriber_user_id,
                            $credit_cycle_id,
                            $event_id,
                            $phone,
                            $credit_type,
                            $credit_limit
                        )
                        : ['result' => 'error', 'reason' => 'ledger_class_missing'];

                    $claim_result = $claim['result'] ?? 'error';

                    if ($claim_result === 'already_consumed') {
                        $queue['results'][$phone] = ['status' => 'skipped_already_consumed', 'time' => current_time('mysql')];
                        continue;
                    }
                    if ($claim_result === 'in_progress') {
                        $queue['results'][$phone] = ['status' => 'skipped_in_progress', 'time' => current_time('mysql')];
                        continue;
                    }
                    if ($claim_result === 'limit_exceeded') {
                        $queue['results'][$phone] = ['status' => 'skipped_limit_exceeded', 'time' => current_time('mysql')];
                        $limit_reached_early = true;
                        continue;
                    }
                    if ($claim_result === 'error') {
                        $queue['results'][$phone] = ['status' => 'ledger_error', 'time' => current_time('mysql')];
                        $this->log("⚠️ Ledger claim error: event=$event_id | phone=$phone | reason=" . ($claim['reason'] ?? 'unknown'));
                        continue;
                    }
                    // 'claimed' فقط تتابع إلى الإرسال الفعلي أدناه.
                }

                $wa_number  = $this->transport->format_number($phone);
                $norm_phone = pge_norm_phone($phone);
                $guest_name = $queue['guests_map'][$phone]['name'] ?? 'ضيفنا العزيز';

                // رمز الضيف الشخصي — fallback للرمز الموحّد
                $guest_code_raw    = $queue['guests_map'][$phone]['code'] ?? '';
                $guest_invite_code = $guest_code_raw !== ''
                    ? (function_exists('pge_normalize_invite_code') ? pge_normalize_invite_code($guest_code_raw) : $guest_code_raw)
                    : $queue['invite_code'];

                // بناء رسالة الدعوة — عبر PGE_Message_Content_Resolver المشترك
                // (Messaging Architecture Phase 1). image_url يُمرَّر صراحةً
                // من $queue['image_url'] (محسوبة مرة واحدة عند بدء الـQueue
                // في ajax_queue_start()، ومخزَّنة/مُخبَّأة عبر تكرارات الـCron —
                // بلا أي تغيير في هذا السلوك المُتعمَّد أصلاً).
                if (class_exists('PGE_Message_Content_Resolver') && class_exists('PGE_Message_Type')) {
                    $content         = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_id, [
                        'guest_name'      => $guest_name,
                        'event_name'      => $queue['event_name'],
                        'event_date'      => $queue['event_date'],
                        'event_date_line' => $queue['event_date'] ? "\n📅 {$queue['event_date']}" : '',
                        'guest_phone'     => $norm_phone,
                        'image_url'       => $queue['image_url'],
                    ]);
                    $caption         = $content['text'];
                    $phone_image_url = $content['image_url'];
                } else {
                    // احتياط دفاعي فقط (لا يُتوقَّع تفعيله عملياً) بنفس المنطق القديم حرفياً.
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
                    $phone_image_url = $queue['image_url'] ?: null;
                }

                $result = $phone_image_url
                    ? $this->transport->send_media($wa_number, $phone_image_url, $caption)
                    : $this->transport->send_text($wa_number, $caption);

                $outcome = $this->transport->interpret_result($result);

                if ($outcome === 'accepted') {
                    // pending ACK — بلا أي تغيير عن السلوك الحالي إطلاقاً
                    $msg_id = $result['id'] ?? '';
                    $pending_data = [
                        'event_id'       => $event_id,
                        'sent_at'        => time(),
                        'msg_id'         => $msg_id,
                        'original_phone' => $norm_phone,
                        'wa_number'      => $wa_number,
                        'event_url'      => $queue['event_url'],
                        'invite_code'    => $guest_invite_code,
                        'norm_phone'     => $norm_phone,
                    ];
                    update_option('pge_wa_pending_' . $wa_number, $pending_data, false);
                    if ($msg_id) {
                        update_option('pge_wa_pending_msgid_' . $msg_id, $pending_data, false);
                    }

                    if ($is_catalog && $claim !== null && ($claim['result'] ?? '') === 'claimed') {
                        $marked = PGE_Invitation_Credit_Ledger::mark_consumed_with_token((int) $claim['id'], (string) ($claim['attempt_token'] ?? ''));
                        if ($marked) {
                            if ($credit_type === 'replacement') {
                                // ══════════════════════════════════════════
                                // استهلاك Entitlement فعلي (المرحلة 4C، القسم
                                // 4-5) — فقط **بعد** نجاح mark_consumed_with_token()
                                // أعلاه بالذات. لا sync_invitation_credit_used()
                                // هنا إطلاقاً (تكتب على _mon_invitation_credit_used
                                // الخاطئ لهذا النوع تماماً — راجع توثيقها).
                                // ══════════════════════════════════════════
                                $this->consume_replacement_entitlement_for_ledger(
                                    $subscriber_user_id,
                                    $credit_cycle_id,
                                    (int) $claim['id'],
                                    $event_id,
                                    $phone
                                );
                            } else {
                                $this->sync_invitation_credit_used($subscriber_user_id, $credit_cycle_id, $credit_type);
                            }
                        } else {
                            $this->log("⚠️ mark_consumed_with_token فشل رغم قبول Cartat: event=$event_id | phone=$phone | ledger_id={$claim['id']}");
                        }
                    }

                    $queue['results'][$phone] = ['status' => 'sent', 'time' => current_time('mysql')];
                } elseif ($outcome === 'rejected') {
                    if ($is_catalog && $claim !== null && ($claim['result'] ?? '') === 'claimed') {
                        PGE_Invitation_Credit_Ledger::mark_failed_with_token((int) $claim['id'], (string) ($claim['attempt_token'] ?? ''));
                    }
                    $queue['results'][$phone] = ['status' => 'failed', 'time' => current_time('mysql')];
                    $this->log("❌ Queue: فشل إرسال (رفض صريح) لـ $wa_number | " . json_encode($result));
                } else {
                    // transport_error: قد تكون الرسالة وصلت فعلاً لكارتات
                    // وانقطع الرد فقط — لا نعتبرها ناجحة ولا نحوّلها failed
                    // تلقائياً، ولا نزيد User Meta، ولا Retry تلقائي هنا. الصف
                    // يبقى reserved بتوكن نشط عمداً (النتيجة غامضة، لا نجزم).
                    //
                    // إصلاح Blocker (Lease — راجع PGE_Invitation_Credit_Ledger
                    // ::ATTEMPT_LEASE_SECONDS/is_lease_expired()): هذا التوكن
                    // لا يبقى نشطاً إلى الأبد — بعد 120 ثانية من
                    // attempt_started_at يُعامَل تلقائياً كصف غير مملوك، ويسمح
                    // claim_for_delivery() اللاحق (محاولة يدوية جديدة من
                    // المضيف فقط، إذ لا Retry تلقائي هنا) بإعادة المطالبة به
                    // بتوكن جديد. توثيق صريح للمخاطرة المتبقية: إن كانت كارتات
                    // قد استلمت المحاولة الأولى فعلاً ولم يصلنا ردها لأي سبب
                    // شبكي، فإعادة المحاولة بعد انتهاء الـLease قد تُرسل رسالة
                    // ثانية فعلياً للمدعو نفسه — هذا احتمال نظري متبقٍّ لا يمكن
                    // حسمه نهائياً دون idempotency key مدعوم من كارتات نفسها
                    // (غير متاح حالياً في عقدها)، لكنه أفضل بوضوح من تجميد
                    // المدعو ورصيده إلى الأبد بلا أي مخرج.
                    $queue['results'][$phone] = ['status' => 'ambiguous_transport_error', 'time' => current_time('mysql')];
                    $this->log("⚠️ Queue: transport_error (لا نعرف هل وصلت الرسالة) لـ $wa_number | event=$event_id | phone=$phone | الصف يبقى reserved حتى انتهاء Lease (" . PGE_Invitation_Credit_Ledger::ATTEMPT_LEASE_SECONDS . " ث) لإعادة محاولة يدوية لاحقة");
                }

                // تأخير عشوائي بين الرسائل
                usleep(rand(2_000_000, 4_000_000));
            }

            if ($limit_reached_early) {
                // إيقاف مبكر موثَّق (القسم ثامناً): لا داعي لمحاولة بقية
                // المدعوين في الدفعات القادمة أيضاً — الرصيد منتهٍ فعلياً.
                $remaining_phones = array_slice($queue['phones'], $queue['offset'] + count($phones));
                foreach ($remaining_phones as $remaining_phone) {
                    $queue['results'][$remaining_phone] = ['status' => 'skipped_limit_exceeded', 'time' => current_time('mysql')];
                }
                $queue['offset'] = $queue['total'];
                $this->log("🛑 Queue: إيقاف مبكر — نفد رصيد الدعوات | event=$event_id");
            } else {
                $queue['offset'] += count($phones);
            }

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
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $cron_lock_name));
        }
    }
}

new Mon_Cartat_Handler();
