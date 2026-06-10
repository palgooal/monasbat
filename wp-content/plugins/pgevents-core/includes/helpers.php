<?php
if (!defined('ABSPATH')) exit;

/**
 * PgEvents Helpers
 * دوال مساعدة مشتركة بين الإضافة والقالب.
 * هذا الملف يُحمَّل أولاً في pgevents-core.php لضمان عدم التكرار.
 */

// ──────────────────────────────────────────────
// تطبيع رقم الجوال: إزالة كل شيء ما عدا الأرقام
// ──────────────────────────────────────────────
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v)
    {
        return preg_replace('/\D+/', '', trim((string) $v));
    }
}

// اسم مستعار للتوافق مع event-guests.php القديم
if (!function_exists('pge_event_guests_norm_phone')) {
    function pge_event_guests_norm_phone($value)
    {
        return pge_norm_phone($value);
    }
}

// ──────────────────────────────────────────────
// جلب قائمة المدعوين كمصفوفة أرقام موحّدة
// ──────────────────────────────────────────────
if (!function_exists('pge_get_invited_phones')) {
    function pge_get_invited_phones($event_id)
    {
        $raw = get_post_meta((int) $event_id, '_pge_invited_phones', true);

        if (is_array($raw)) {
            $phones = $raw;
        } else {
            $raw    = str_replace(["\r\n", "\r"], "\n", (string) $raw);
            $phones = array_filter(array_map('trim', explode("\n", $raw)));
        }

        $out = [];
        foreach ($phones as $p) {
            $n = pge_norm_phone($p);
            if ($n !== '') $out[] = $n;
        }

        return array_values(array_unique($out));
    }
}

// ──────────────────────────────────────────────
// تطبيع رمز الدعوة (8 أحرف أبجدية رقمية بشرطة)
// ──────────────────────────────────────────────
if (!function_exists('pge_normalize_invite_code')) {
    function pge_normalize_invite_code($code)
    {
        $code = strtoupper(trim((string) $code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === '') return '';

        $code = substr($code, 0, 8);
        if (strlen($code) > 4) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }

        return $code;
    }
}

// اسم مستعار للتوافق مع access-gate.php القديم
if (!function_exists('pge_norm_invite_code')) {
    function pge_norm_invite_code($code)
    {
        return pge_normalize_invite_code($code);
    }
}

// ──────────────────────────────────────────────
// توليد رمز دعوة عشوائي (XXXX-XXXX)
// ──────────────────────────────────────────────
if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw   = '';
        $max   = strlen($chars) - 1;

        for ($i = 0; $i < 8; $i++) {
            $raw .= $chars[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
}

// ──────────────────────────────────────────────
// توليد رابط QR Code عبر api.qrserver.com
// ──────────────────────────────────────────────
if (!function_exists('pge_generate_qr_url')) {
    /**
     * يُولّد رابط صورة QR للبيانات المحددة.
     * يعمل بدون أي مكتبة — الصورة تُخدَّم مباشرة من API خارجي.
     *
     * @param string $data  النص المشفَّر في QR (رمز الدعوة مثلاً)
     * @param int    $size  حجم الصورة بالبكسل (افتراضي: 400)
     * @return string رابط URL للصورة
     */
    function pge_generate_qr_url(string $data, int $size = 400): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/'
            . '?size='   . $size . 'x' . $size
            . '&data='   . rawurlencode($data)
            . '&margin=15'
            . '&color=000000'
            . '&bgcolor=ffffff';
    }
}

// ──────────────────────────────────────────────
// رابط الدعوة القصير: /e/{ID}
// ──────────────────────────────────────────────
if (!function_exists('pge_get_event_short_url')) {
    function pge_get_event_short_url(int $event_id): string
    {
        return rtrim(home_url('/e/' . $event_id), '/');
    }
}

// ══════════════════════════════════════════════
// Static Map — استخراج الإحداثيات وبناء صورة الخريطة
// ══════════════════════════════════════════════

/**
 * يتتبع redirects رابط Google Maps يدوياً (HEAD فقط = سريع)
 * ويستخرج lat,lng من أول URL يحتوي على الصيغة /@lat,lng,zoom
 */
if (!function_exists('pge_extract_maps_coordinates')) {
    function pge_extract_maps_coordinates(string $maps_url): ?array
    {
        $url = $maps_url;
        for ($i = 0; $i < 8; $i++) {
            // فحص الـ URL الحالي أولاً
            if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
                return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
            }

            $response = wp_remote_head($url, [
                'timeout'    => 8,
                'redirection'=> 0,   // لا نتبع تلقائياً — نتحكم يدوياً
                'sslverify'  => true,
            ]);

            if (is_wp_error($response)) return null;

            $code     = (int) wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');

            if ($code >= 300 && $code < 400 && $location) {
                // فحص الـ location header قبل المتابعة
                if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $location, $m)) {
                    return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
                }
                $url = $location;
            } else {
                break;
            }
        }
        return null;
    }
}

/**
 * يبني رابط صورة خريطة ثابتة.
 * — إذا توفر Google Static Maps API Key: يستخدمه (جودة أعلى).
 * — بدون key: يستخدم OpenStreetMap مجاناً.
 */
if (!function_exists('pge_build_static_map_url')) {
    function pge_build_static_map_url(float $lat, float $lng): string
    {
        $api_key = (string) get_option('pge_google_maps_api_key', '');

        if ($api_key !== '') {
            return 'https://maps.googleapis.com/maps/api/staticmap'
                . '?center=' . $lat . ',' . $lng
                . '&zoom=15'
                . '&size=600x400'
                . '&scale=2'
                . '&maptype=roadmap'
                . '&markers=color:red%7C' . $lat . ',' . $lng
                . '&key=' . rawurlencode($api_key);
        }

        // Fallback مجاني — OpenStreetMap Static Map
        return 'https://staticmap.openstreetmap.de/staticmap.php'
            . '?center=' . $lat . ',' . $lng
            . '&zoom=15'
            . '&size=600x400'
            . '&markers=' . $lat . ',' . $lng . ',red-pushpin';
    }
}

// ══════════════════════════════════════════════
// قوالب رسائل WhatsApp التلقائية
// ══════════════════════════════════════════════

if (!function_exists('pge_wa_default_invite_template')) {
    function pge_wa_default_invite_template(): string
    {
        return "مرحباً *{{guest_name}}* 👋\n\nيسعدنا دعوتك لحضور:\n✨ *{{event_name}}*{{event_date_line}}\n\n━━━━━━━━━━━━━━━\nللرد على الدعوة أرسل:\n✅ *1* — سأحضر بإذن الله\n❌ *2* — لن أتمكن من الحضور";
    }
}

if (!function_exists('pge_wa_default_reply_yes_template')) {
    function pge_wa_default_reply_yes_template(): string
    {
        return "شكراً على تأكيد حضورك! 🎉\nنتطلع لرؤيتك في *{{event_name}}*\n\n━━━━━━━━━━━━━━━\n📌 *تفاصيل دخولك:*\n🔗 رابط المناسبة:\n{{event_url}}\n\n🔑 رمز الدعوة: *{{invite_code}}*\n📱 رقمك المسجل: *{{guest_phone}}*{{location_line}}";
    }
}

if (!function_exists('pge_wa_default_reply_no_template')) {
    function pge_wa_default_reply_no_template(): string
    {
        return "شكراً على إبلاغنا. نتمنى لك دوام الصحة والسعادة 🌸";
    }
}

if (!function_exists('pge_wa_default_reply_invalid_template')) {
    function pge_wa_default_reply_invalid_template(): string
    {
        return "عذراً، لم نتعرف على ردك 😊\n\nأرسل *1* لتأكيد الحضور\nأو *2* للاعتذار";
    }
}

/**
 * جلب قوالب رسائل المناسبة (مع fallback للقيم الافتراضية)
 */
if (!function_exists('pge_wa_get_templates')) {
    function pge_wa_get_templates(int $event_id): array
    {
        $stored_invite  = (string) get_post_meta($event_id, '_pge_wa_tpl_invite',  true);
        $stored_yes     = (string) get_post_meta($event_id, '_pge_wa_tpl_yes',     true);
        $stored_no      = (string) get_post_meta($event_id, '_pge_wa_tpl_no',      true);
        $stored_invalid = (string) get_post_meta($event_id, '_pge_wa_tpl_invalid', true);

        return [
            'invite'  => $stored_invite  !== '' ? $stored_invite  : pge_wa_default_invite_template(),
            'yes'     => $stored_yes     !== '' ? $stored_yes     : pge_wa_default_reply_yes_template(),
            'no'      => $stored_no      !== '' ? $stored_no      : pge_wa_default_reply_no_template(),
            'invalid' => $stored_invalid !== '' ? $stored_invalid : pge_wa_default_reply_invalid_template(),
        ];
    }
}

/**
 * تصيير قالب واتساب باستبدال المتغيرات
 */
if (!function_exists('pge_wa_render_template')) {
    function pge_wa_render_template(string $template, array $vars): string
    {
        foreach ($vars as $key => $val) {
            $template = str_replace('{{' . $key . '}}', (string) $val, $template);
        }
        return trim($template);
    }
}

// ──────────────────────────────────────────────
// هل المستخدم الحالي مضيف المناسبة أو أدمن؟
// ──────────────────────────────────────────────
if (!function_exists('pge_is_host_or_admin')) {
    function pge_is_host_or_admin($event_id)
    {
        if (current_user_can('administrator')) return true;
        $uid = get_current_user_id();
        if (!$uid) return false;
        return $uid === (int) get_post_field('post_author', (int) $event_id);
    }
}
