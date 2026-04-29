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
