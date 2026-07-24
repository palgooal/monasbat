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

        if ($api_key === '') return '';

        return 'https://maps.googleapis.com/maps/api/staticmap'
            . '?center=' . $lat . ',' . $lng
            . '&zoom=15'
            . '&size=600x400'
            . '&scale=2'
            . '&maptype=roadmap'
            . '&markers=color:red%7C' . $lat . ',' . $lng
            . '&key=' . rawurlencode($api_key);
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

if (!function_exists('pge_admin_safe_meta_string')) {
    /**
     * قراءة آمنة لمفتاح User Meta كنص، تُستخدم داخل الدوال الآتية المخصصة
     * لعرض بيانات الاشتراك في /wp-admin/users.php. لا تفترض أن القيمة
     * المخزَّنة نصّية دائماً (قد تكون فارغة، أو array عالقة من بيانات قديمة
     * تالفة، أو أي نوع آخر غير متوقع) — أي شيء غير نصي/رقمي بسيط يُعامَل
     * كنص فارغ بدل تمريره لأي (string) cast مباشر قد يُصدر تحذير
     * "Array to string conversion" على مصفوفة. تُعيد دائماً نصاً مُقلَّم
     * (trim) بلا أي Warning أو Notice في كل الحالات.
     */
    function pge_admin_safe_meta_string($user_id, $key)
    {
        $raw = get_user_meta((int) $user_id, $key, true);

        if (is_string($raw)) {
            return trim($raw);
        }
        if (is_scalar($raw)) {
            return trim((string) $raw);
        }

        return '';
    }
}

if (!function_exists('pge_resolve_admin_user_package_name')) {
    /**
     * اسم الباقة المعروض في عمود "الباقة الحالية" بصفحة /wp-admin/users.php.
     * قراءة فقط — لا تكتب أي شيء، ولا تدمج بيانات Catalog وLegacy لنفس
     * المستخدم إطلاقاً.
     *
     * إن كان _mon_package_source === 'catalog': الاسم يُبنى حصراً من
     * _mon_catalog_plan_name/_mon_catalog_plan_key و
     * _mon_catalog_tier_name/_mon_catalog_tier_key بصيغة "الخطة — المستوى"،
     * بلا أي رجوع إلى _mon_package_name القديم مهما كان موجوداً.
     * خلاف ذلك (Legacy أو بلا مصدر): _mon_package_name كما كان تماماً، أو
     * '—' إن كانت فارغة.
     */
    function pge_resolve_admin_user_package_name($user_id)
    {
        $source = pge_admin_safe_meta_string($user_id, '_mon_package_source');

        if ($source === 'catalog') {
            $plan_name = pge_admin_safe_meta_string($user_id, '_mon_catalog_plan_name');
            $plan_key  = pge_admin_safe_meta_string($user_id, '_mon_catalog_plan_key');
            $tier_name = pge_admin_safe_meta_string($user_id, '_mon_catalog_tier_name');
            $tier_key  = pge_admin_safe_meta_string($user_id, '_mon_catalog_tier_key');

            $plan_part = $plan_name !== '' ? $plan_name : $plan_key;
            $tier_part = $tier_name !== '' ? $tier_name : $tier_key;

            if ($plan_part !== '' && $tier_part !== '') {
                return $plan_part . ' — ' . $tier_part;
            }
            if ($plan_part !== '') {
                return $plan_part;
            }
            if ($tier_part !== '') {
                return $tier_part;
            }

            return 'بيانات Catalog غير مكتملة';
        }

        $legacy_name = pge_admin_safe_meta_string($user_id, '_mon_package_name');
        return $legacy_name !== '' ? $legacy_name : '—';
    }
}

if (!function_exists('pge_resolve_admin_user_package_source')) {
    /**
     * تسمية "مصدر الاشتراك" المعروضة بصفحة /wp-admin/users.php.
     *
     * _created_via_salla لا يُستخدم هنا إطلاقاً — هو يصف طريقة إنشاء
     * الحساب (سلة/يدوي) وليس مصدر الاشتراك، ويبقى كما هو لأي استخدام آخر
     * قائم في المشروع.
     *
     * القاعدة: catalog → 'Catalog'. غير ذلك مع وجود مؤشر Legacy فعلي
     * (_mon_package_key أو _mon_package_name أو _mon_package_status غير
     * فارغة) → 'Legacy'. لا شيء من ذلك → 'بدون اشتراك'.
     */
    function pge_resolve_admin_user_package_source($user_id)
    {
        $source = pge_admin_safe_meta_string($user_id, '_mon_package_source');

        if ($source === 'catalog') {
            return 'Catalog';
        }

        $has_legacy_indicator = (
            pge_admin_safe_meta_string($user_id, '_mon_package_key') !== ''
            || pge_admin_safe_meta_string($user_id, '_mon_package_name') !== ''
            || pge_admin_safe_meta_string($user_id, '_mon_package_status') !== ''
        );

        return $has_legacy_indicator ? 'Legacy' : 'بدون اشتراك';
    }
}

if (!function_exists('pge_is_legacy_write_allowed_for_user')) {
    /**
     * هل يُسمح لأي مسار Legacy (إسناد باقة يدوياً، إلغاء طلب Legacy قديم،
     * ...) بالكتابة على مفاتيح اشتراك هذا المستخدم؟
     *
     * القرار المعماري: إن كان مصدر الاشتراك الحالي لدى المستخدم Catalog
     * (_mon_package_source === 'catalog')، فـCatalog هو مصدر الحقيقة الوحيد
     * لاشتراكه — بصرف النظر عن كون هذا الاشتراك active أو expired حالياً —
     * ويُمنع أي مسار Legacy من الكتابة فوقه إطلاقاً. لا يوجد أي fallback أو
     * مزج بين النظامين. هذه الدالة قراءة فقط ولا تُعدّل أي شيء؛ الاستدعاء
     * يجب أن يحدث قبل أي update_user_meta/delete_user_meta في المسار
     * المستدعي، وعلى المستدعي هو من يوقف التنفيذ إن أعادت false.
     */
    function pge_is_legacy_write_allowed_for_user($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return true; // لا مستخدم صالح لحمايته أصلاً — القرار يعود للمستدعي
        }

        return get_user_meta($user_id, '_mon_package_source', true) !== 'catalog';
    }
}
