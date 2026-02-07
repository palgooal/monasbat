<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('pge_event_guests_norm_phone')) {
    function pge_event_guests_norm_phone($value)
    {
        return preg_replace('/\D+/', '', trim((string) $value));
    }
}

if (!function_exists('pge_event_guests_user_can_manage')) {
    function pge_event_guests_user_can_manage($event_id)
    {
        $event_id = (int) $event_id;
        if (!$event_id) return false;

        $uid = get_current_user_id();
        $author_id = (int) get_post_field('post_author', $event_id);

        if (current_user_can('administrator')) return true;
        if ($uid && $uid === $author_id) return true;
        if (current_user_can('edit_post', $event_id)) return true;

        return false;
    }
}

if (!function_exists('pge_event_guests_parse_phones_meta')) {
    function pge_event_guests_parse_phones_meta($event_id)
    {
        $raw = get_post_meta($event_id, '_pge_invited_phones', true);

        if (is_array($raw)) {
            $phones = $raw;
        } else {
            $raw = (string) $raw;
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $phones = array_filter(array_map('trim', explode("\n", $raw)));
        }

        $out = [];
        foreach ($phones as $phone) {
            $norm = pge_event_guests_norm_phone($phone);
            if ($norm !== '') $out[$norm] = $norm;
        }

        return array_values($out);
    }
}

if (!function_exists('pge_event_guests_get_map')) {
    function pge_event_guests_get_map($event_id)
    {
        $event_id = (int) $event_id;
        $stored = get_post_meta($event_id, '_pge_invited_guests', true);
        $map = [];

        if (is_array($stored)) {
            foreach ($stored as $key => $guest) {
                if (!is_array($guest)) {
                    $phone = pge_event_guests_norm_phone(is_string($guest) ? $guest : $key);
                    if ($phone === '') continue;
                    $map[$phone] = [
                        'phone' => $phone,
                        'name'  => '',
                        'note'  => '',
                    ];
                    continue;
                }

                $phone = pge_event_guests_norm_phone($guest['phone'] ?? $key);
                if ($phone === '') continue;

                $map[$phone] = [
                    'phone' => $phone,
                    'name'  => sanitize_text_field((string) ($guest['name'] ?? '')),
                    'note'  => sanitize_textarea_field((string) ($guest['note'] ?? '')),
                ];
            }
        }

        if (empty($map)) {
            $legacy_phones = pge_event_guests_parse_phones_meta($event_id);
            foreach ($legacy_phones as $phone) {
                $map[$phone] = [
                    'phone' => $phone,
                    'name'  => '',
                    'note'  => '',
                ];
            }
        }

        return $map;
    }
}

if (!function_exists('pge_event_guests_save_map')) {
    function pge_event_guests_save_map($event_id, $map)
    {
        $event_id = (int) $event_id;
        $clean = [];

        foreach ((array) $map as $guest) {
            if (!is_array($guest)) continue;

            $phone = pge_event_guests_norm_phone($guest['phone'] ?? '');
            if ($phone === '') continue;

            $clean[$phone] = [
                'phone' => $phone,
                'name'  => sanitize_text_field((string) ($guest['name'] ?? '')),
                'note'  => sanitize_textarea_field((string) ($guest['note'] ?? '')),
            ];
        }

        update_post_meta($event_id, '_pge_invited_guests', $clean);
        update_post_meta($event_id, '_pge_invited_phones', array_keys($clean));

        return $clean;
    }
}

if (!function_exists('pge_event_guests_get_status_label')) {
    function pge_event_guests_get_status_label($status)
    {
        if ($status === 'yes') return 'سيحضر';
        if ($status === 'no') return 'اعتذر';
        return 'لم يرد';
    }
}

if (!function_exists('pge_event_guests_get_row_payload')) {
    function pge_event_guests_get_row_payload($event_id, $guest)
    {
        $phone = pge_event_guests_norm_phone($guest['phone'] ?? '');
        $name = sanitize_text_field((string) ($guest['name'] ?? ''));
        $note = sanitize_textarea_field((string) ($guest['note'] ?? ''));

        $rsvp_map = (array) get_post_meta($event_id, '_pge_rsvp_map', true);
        $row = $rsvp_map[$phone] ?? null;
        $reply = is_array($row) ? (string) ($row['reply'] ?? '') : '';
        $status = ($reply === 'yes' || $reply === 'no') ? $reply : 'pending';

        $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
        $checked = isset($checkins[$phone]) ? 'yes' : 'no';

        return [
            'phone'        => $phone,
            'name'         => $name,
            'note'         => $note,
            'status'       => $status,
            'status_label' => pge_event_guests_get_status_label($status),
            'checked'      => $checked,
        ];
    }
}

if (!function_exists('pge_event_guests_get_stats')) {
    function pge_event_guests_get_stats($event_id, $guests_map = null)
    {
        if (!is_array($guests_map)) {
            $guests_map = pge_event_guests_get_map($event_id);
        }

        $phones = array_keys($guests_map);
        $total = count($phones);
        $yes = 0;
        $no = 0;
        $checked = 0;

        $rsvp_map = (array) get_post_meta($event_id, '_pge_rsvp_map', true);
        $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);

        foreach ($phones as $phone) {
            $row = $rsvp_map[$phone] ?? null;
            if (is_array($row) && ($row['reply'] ?? '') === 'yes') $yes++;
            if (is_array($row) && ($row['reply'] ?? '') === 'no') $no++;
            if (isset($checkins[$phone])) $checked++;
        }

        $pending = max(0, $total - ($yes + $no));

        return [
            'total'   => $total,
            'yes'     => $yes,
            'no'      => $no,
            'pending' => $pending,
            'checked' => $checked,
        ];
    }
}

if (!function_exists('pge_event_guests_validate_request')) {
    function pge_event_guests_validate_request()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error('غير مصرح');
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
            wp_send_json_error('رمز الأمان غير صالح');
        }

        $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
        if (!$event_id || get_post_type($event_id) !== 'pge_event') {
            wp_send_json_error('مناسبة غير صالحة');
        }

        if (!pge_event_guests_user_can_manage($event_id)) {
            wp_send_json_error('ليس لديك صلاحية إدارة هذه المناسبة');
        }

        return $event_id;
    }
}

if (!function_exists('pge_event_guests_migrate_phone_refs')) {
    function pge_event_guests_migrate_phone_refs($event_id, $old_phone, $new_phone)
    {
        if ($old_phone === $new_phone || $old_phone === '' || $new_phone === '') return;

        $rsvp_map = (array) get_post_meta($event_id, '_pge_rsvp_map', true);
        if (isset($rsvp_map[$old_phone])) {
            if (!isset($rsvp_map[$new_phone])) {
                $rsvp_map[$new_phone] = $rsvp_map[$old_phone];
            }
            unset($rsvp_map[$old_phone]);
            update_post_meta($event_id, '_pge_rsvp_map', $rsvp_map);
        }

        $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
        if (isset($checkins[$old_phone])) {
            if (!isset($checkins[$new_phone])) {
                $checkins[$new_phone] = $checkins[$old_phone];
            }
            unset($checkins[$old_phone]);
            update_post_meta($event_id, '_pge_checkins', $checkins);
        }
    }
}

if (!function_exists('pge_event_guests_remove_phone_refs')) {
    function pge_event_guests_remove_phone_refs($event_id, $phone)
    {
        if ($phone === '') return;

        $rsvp_map = (array) get_post_meta($event_id, '_pge_rsvp_map', true);
        if (isset($rsvp_map[$phone])) {
            unset($rsvp_map[$phone]);
            update_post_meta($event_id, '_pge_rsvp_map', $rsvp_map);
        }

        $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
        if (isset($checkins[$phone])) {
            unset($checkins[$phone]);
            update_post_meta($event_id, '_pge_checkins', $checkins);
        }
    }
}

add_action('wp_ajax_pge_event_guest_add', function () {
    $event_id = pge_event_guests_validate_request();

    $phone = pge_event_guests_norm_phone($_POST['phone'] ?? '');
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

    if ($phone === '') {
        wp_send_json_error('أدخل رقم جوال صحيح');
    }

    $guests_map = pge_event_guests_get_map($event_id);
    if (isset($guests_map[$phone])) {
        wp_send_json_error('هذا الرقم موجود مسبقًا ضمن المدعوين');
    }

    $guests_map[$phone] = [
        'phone' => $phone,
        'name'  => $name,
        'note'  => $note,
    ];

    $guests_map = pge_event_guests_save_map($event_id, $guests_map);

    wp_send_json_success([
        'message' => 'تمت إضافة المدعو',
        'guest'   => pge_event_guests_get_row_payload($event_id, $guests_map[$phone]),
        'stats'   => pge_event_guests_get_stats($event_id, $guests_map),
    ]);
});

add_action('wp_ajax_pge_event_guest_bulk_add', function () {
    $event_id = pge_event_guests_validate_request();
    $phones_text = isset($_POST['phones_text']) ? sanitize_textarea_field(wp_unslash($_POST['phones_text'])) : '';

    if ($phones_text === '') {
        wp_send_json_error('أدخل أرقام الجوال لإضافتها');
    }

    $guests_map = pge_event_guests_get_map($event_id);
    $raw_lines = str_replace(["\r\n", "\r"], "\n", $phones_text);
    $lines = array_filter(array_map('trim', explode("\n", $raw_lines)));

    $added = 0;
    $skipped = 0;
    $invalid = 0;

    foreach ($lines as $line) {
        $phone = pge_event_guests_norm_phone($line);
        if ($phone === '') {
            $invalid++;
            continue;
        }

        if (isset($guests_map[$phone])) {
            $skipped++;
            continue;
        }

        $guests_map[$phone] = [
            'phone' => $phone,
            'name'  => '',
            'note'  => '',
        ];
        $added++;
    }

    $guests_map = pge_event_guests_save_map($event_id, $guests_map);

    $message = sprintf('تمت إضافة %d رقم. تم تجاهل %d مكرر و %d غير صالح.', $added, $skipped, $invalid);

    wp_send_json_success([
        'message' => $message,
        'added'   => $added,
        'skipped' => $skipped,
        'invalid' => $invalid,
        'stats'   => pge_event_guests_get_stats($event_id, $guests_map),
    ]);
});

add_action('wp_ajax_pge_event_guest_update', function () {
    $event_id = pge_event_guests_validate_request();

    $old_phone = pge_event_guests_norm_phone($_POST['old_phone'] ?? '');
    $new_phone = pge_event_guests_norm_phone($_POST['phone'] ?? '');
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

    if ($old_phone === '' || $new_phone === '') {
        wp_send_json_error('بيانات المدعو غير مكتملة');
    }

    $guests_map = pge_event_guests_get_map($event_id);
    if (!isset($guests_map[$old_phone])) {
        wp_send_json_error('المدعو غير موجود');
    }

    if ($old_phone !== $new_phone && isset($guests_map[$new_phone])) {
        wp_send_json_error('رقم الجوال الجديد مستخدم بالفعل');
    }

    unset($guests_map[$old_phone]);
    $guests_map[$new_phone] = [
        'phone' => $new_phone,
        'name'  => $name,
        'note'  => $note,
    ];

    pge_event_guests_migrate_phone_refs($event_id, $old_phone, $new_phone);
    $guests_map = pge_event_guests_save_map($event_id, $guests_map);

    wp_send_json_success([
        'message' => 'تم تحديث بيانات المدعو',
        'guest'   => pge_event_guests_get_row_payload($event_id, $guests_map[$new_phone]),
        'stats'   => pge_event_guests_get_stats($event_id, $guests_map),
    ]);
});

add_action('wp_ajax_pge_event_guest_delete', function () {
    $event_id = pge_event_guests_validate_request();
    $phone = pge_event_guests_norm_phone($_POST['phone'] ?? '');

    if ($phone === '') {
        wp_send_json_error('رقم الجوال غير صالح');
    }

    $guests_map = pge_event_guests_get_map($event_id);
    if (!isset($guests_map[$phone])) {
        wp_send_json_error('المدعو غير موجود');
    }

    unset($guests_map[$phone]);
    pge_event_guests_remove_phone_refs($event_id, $phone);
    $guests_map = pge_event_guests_save_map($event_id, $guests_map);

    wp_send_json_success([
        'message' => 'تم حذف المدعو',
        'stats'   => pge_event_guests_get_stats($event_id, $guests_map),
    ]);
});

add_action('wp_ajax_pge_event_guest_bulk_delete', function () {
    $event_id = pge_event_guests_validate_request();

    $phones_raw = $_POST['phones'] ?? '';
    if (is_array($phones_raw)) {
        $candidates = $phones_raw;
    } else {
        $candidates = preg_split('/[\s,]+/', (string) $phones_raw);
    }

    $phones = [];
    foreach ((array) $candidates as $candidate) {
        $phone = pge_event_guests_norm_phone($candidate);
        if ($phone !== '') $phones[$phone] = $phone;
    }
    $phones = array_values($phones);

    if (empty($phones)) {
        wp_send_json_error('اختر مدعوين للحذف');
    }

    $guests_map = pge_event_guests_get_map($event_id);
    $deleted = 0;

    foreach ($phones as $phone) {
        if (!isset($guests_map[$phone])) continue;
        unset($guests_map[$phone]);
        pge_event_guests_remove_phone_refs($event_id, $phone);
        $deleted++;
    }

    $guests_map = pge_event_guests_save_map($event_id, $guests_map);

    wp_send_json_success([
        'message' => sprintf('تم حذف %d مدعو.', $deleted),
        'deleted' => $deleted,
        'stats'   => pge_event_guests_get_stats($event_id, $guests_map),
    ]);
});
