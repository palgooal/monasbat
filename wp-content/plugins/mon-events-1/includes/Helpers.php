<?php

namespace MonEvents;

if (!defined('ABSPATH')) exit;

class Helpers
{
    public function normalize_phone($raw, $default_cc = '966'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (!$digits) return '';

        if (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }

        // 05xxxxxxxx => 9665xxxxxxxx
        if (strlen($digits) === 10 && substr($digits, 0, 1) === '0') {
            $digits = $default_cc . substr($digits, 1);
        }

        // 5xxxxxxxx => 9665xxxxxxxx
        if (strlen($digits) === 9 && substr($digits, 0, 1) === '5') {
            $digits = $default_cc . $digits;
        }

        return $digits;
    }

    public function parse_invites_from_raw_list($raw): array
    {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        $out = [];
        foreach ($parts as $p) {
            $phone = $this->normalize_phone(trim($p));
            if (!$phone) continue;
            $out[$phone] = ['name' => ''];
        }
        return $out;
    }

    public function parse_invites_from_csv($csv_text): array
    {
        $out = [];
        $lines = preg_split("/\r\n|\n|\r/", (string) $csv_text);
        $row_index = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $cols = str_getcsv($line);
            if (empty($cols)) continue;

            $col0 = strtolower(trim((string)($cols[0] ?? '')));
            $col1 = strtolower(trim((string)($cols[1] ?? '')));

            if ($row_index === 0) {
                if ($col0 === 'phone' || $col0 === 'mobile' || $col0 === 'number') {
                    $row_index++;
                    continue;
                }
                if ($col0 === 'phone' && $col1 === 'name') {
                    $row_index++;
                    continue;
                }
            }

            $phone = $this->normalize_phone((string)($cols[0] ?? ''));
            if (!$phone) {
                $row_index++;
                continue;
            }

            $name_raw  = (string)($cols[1] ?? '');
            $out[$phone] = ['name' => sanitize_text_field($name_raw)];
            $row_index++;
        }

        return $out;
    }

    public function read_csv_file_content($tmp_path): string
    {
        $content = @file_get_contents($tmp_path);
        if (!is_string($content) || $content === '') return '';

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (strlen($content) > 1024 * 1024) {
            $content = substr($content, 0, 1024 * 1024);
        }

        return trim($content);
    }

    public function make_invite_cookie_value($event_id, $phone_norm): string
    {
        $payload = (int)$event_id . '|' . (string)$phone_norm;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return base64_encode($payload . '|' . $sig);
    }

    public function verify_invite_cookie_value($cookie_value): array
    {
        $decoded = base64_decode((string) $cookie_value, true);
        if (!$decoded) return [false, 0, ''];

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) return [false, 0, ''];

        [$event_id, $phone_norm, $sig] = $parts;
        $event_id = (int) $event_id;
        $phone_norm = (string) $phone_norm;

        if ($event_id <= 0 || !$phone_norm || !$sig) return [false, 0, ''];

        $payload = $event_id . '|' . $phone_norm;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));
        if (!hash_equals($expected, $sig)) return [false, 0, ''];

        return [true, $event_id, $phone_norm];
    }
}
