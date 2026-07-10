<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * ترحيل ردود RSVP القديمة من Post Meta (_pge_rsvp_map / _pge_rsvp_records)
 * إلى الجدول الحقيقي wp_pge_event_rsvps — مصدر الحقيقة الوحيد المعتمد.
 * ============================================================================
 *
 * هذا الملف لا يحذف أي بيانات قديمة ولا يُشغّل شيئاً تلقائياً. الترحيل يبدأ
 * فقط عندما يضغط أدمن الموقع على زر صريح من لوحة التحكم (صفحة "ترحيل RSVP").
 *
 * القواعد:
 *  - لا تكرار صفوف: الهوية الفريدة في الجدول هي (event_id, guest_phone).
 *  - لا استبدال بيانات SQL أحدث ببيانات Post Meta أقدم — إذا كان للصف الموجود
 *    في SQL طابع زمني (updated_at) أحدث من أو يساوي الطابع الزمني القديم، يُتخطّى.
 *  - أي حالة تعارض غير مؤكدة (بيانات SQL مختلفة بدون طابع زمني قديم موثوق) تُسجَّل
 *    كـ "تعارض" ولا تُطبَّق تلقائياً — يحتاج مراجعة يدوية.
 *  - المعالجة على دفعات محدودة العدد (افتراضياً 25 مناسبة لكل تشغيلة) لتفادي أي
 *    مهلة تنفيذ (timeout) على المواقع الكبيرة.
 */

/**
 * الدالة المركزية للترحيل. آمنة للتكرار (idempotent) — تشغيلها أكثر من مرة على
 * نفس البيانات لا يُنتج صفوفاً مكررة ولا يُعيد كتابة نفس القيم.
 *
 * @param array{dry_run?:bool,batch_size?:int,offset?:int} $args
 * @return array تقرير كامل: scanned/migrated/skipped/conflicts/invalid + معلومات الدفعة التالية
 */
if (!function_exists('pge_migrate_legacy_rsvp_meta')) {
    function pge_migrate_legacy_rsvp_meta(array $args = []): array
    {
        $args = array_merge([
            'dry_run'    => true,
            'batch_size' => 25,
            'offset'     => 0,
        ], $args);

        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';

        $report = [
            'dry_run'                       => (bool) $args['dry_run'],
            'batch_size'                     => (int) $args['batch_size'],
            'offset'                         => (int) $args['offset'],
            'events_scanned'                 => 0,
            'scanned'                        => 0,
            'migrated'                       => 0,
            'skipped'                        => 0,
            'conflicts'                      => 0,
            'invalid'                        => 0,
            'events'                         => [],
            'has_more'                       => false,
            'next_offset'                    => null,
            'total_events_with_legacy_data'  => 0,
        ];

        // فقط المناسبات التي تحمل فعلياً بيانات RSVP قديمة (تحسين أداء)
        $total_events_with_legacy = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_pge_rsvp_map', '_pge_rsvp_records')
             WHERE p.post_type = 'pge_event'"
        );
        $report['total_events_with_legacy_data'] = $total_events_with_legacy;

        $event_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_pge_rsvp_map', '_pge_rsvp_records')
             WHERE p.post_type = %s
             ORDER BY p.ID ASC
             LIMIT %d OFFSET %d",
            'pge_event',
            (int) $args['batch_size'],
            (int) $args['offset']
        ));

        $reply_map = [
            'yes' => 'yes', 'attending' => 'yes', 'confirmed' => 'yes', '1' => 'yes',
            'no' => 'no', 'declined' => 'no', 'decline' => 'no', '0' => 'no',
            'pending' => 'pending',
        ];

        foreach ($event_ids as $event_id) {
            $event_id = (int) $event_id;
            $report['events_scanned']++;
            $event_detail = ['event_id' => $event_id, 'migrated' => 0, 'skipped' => 0, 'conflicts' => 0, 'invalid' => 0];

            $records = get_post_meta($event_id, '_pge_rsvp_map', true);
            if (!is_array($records)) $records = get_post_meta($event_id, '_pge_rsvp_records', true);
            if (!is_array($records)) $records = [];

            foreach ($records as $key => $rec) {
                $report['scanned']++;

                // استخراج هوية الرقم من مفتاح المصفوفة: 'g_{phone}' أو 'host_{user_id}'
                $phone = '';
                if (strpos((string) $key, 'g_') === 0) {
                    $phone = function_exists('pge_norm_phone')
                        ? pge_norm_phone(substr((string) $key, 2))
                        : preg_replace('/\D+/', '', substr((string) $key, 2));
                } elseif (strpos((string) $key, 'host_') === 0) {
                    $host_phone_raw = (string) get_post_meta($event_id, '_pge_host_phone', true);
                    $phone = function_exists('pge_norm_phone')
                        ? pge_norm_phone($host_phone_raw)
                        : preg_replace('/\D+/', '', $host_phone_raw);
                }

                if ($phone === '') {
                    $report['invalid']++;
                    $event_detail['invalid']++;
                    continue;
                }

                $legacy_reply = strtolower(trim((string) ($rec['reply'] ?? '')));
                $reply = $reply_map[$legacy_reply] ?? null;

                if ($reply === null) {
                    $report['invalid']++;
                    $event_detail['invalid']++;
                    continue;
                }

                $companions = max(0, min(20, (int) ($rec['companions'] ?? 0)));
                $note       = trim((string) ($rec['note'] ?? ''));
                $legacy_ts  = !empty($rec['updated_at']) ? strtotime((string) $rec['updated_at']) : 0;

                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, reply, companions, note, updated_at FROM {$table} WHERE event_id = %d AND guest_phone = %s LIMIT 1",
                    $event_id,
                    $phone
                ));

                if ($existing) {
                    // مطابق أصلاً — لا شيء لفعله
                    if ((string) $existing->reply === $reply
                        && (int) $existing->companions === $companions
                        && (string) $existing->note === $note) {
                        $report['skipped']++;
                        $event_detail['skipped']++;
                        continue;
                    }

                    $sql_ts = $existing->updated_at ? strtotime($existing->updated_at) : 0;

                    // بيانات SQL أحدث من (أو تساوي) القديمة — لا تُستبدَل ببيانات أقدم
                    if ($legacy_ts && $sql_ts && $legacy_ts <= $sql_ts) {
                        $report['skipped']++;
                        $event_detail['skipped']++;
                        continue;
                    }

                    // تعارض غير مؤكَّد (لا طابع زمني قديم موثوق لتقرير الأسبقية) — لا يُطبَّق تلقائياً
                    if (!$legacy_ts) {
                        $report['conflicts']++;
                        $event_detail['conflicts']++;
                        continue;
                    }

                    if (!$args['dry_run']) {
                        $wpdb->update(
                            $table,
                            ['reply' => $reply, 'companions' => $companions, 'note' => $note],
                            ['id' => (int) $existing->id],
                            ['%s', '%d', '%s'],
                            ['%d']
                        );
                    }
                    $report['migrated']++;
                    $event_detail['migrated']++;
                } else {
                    if (!$args['dry_run']) {
                        $insert_data = [
                            'event_id'    => $event_id,
                            'guest_phone' => $phone,
                            'companions'  => $companions,
                            'note'        => $note,
                            'reply'       => $reply,
                        ];
                        $formats = ['%d', '%s', '%d', '%s', '%s'];

                        if ($legacy_ts) {
                            $insert_data['created_at'] = date('Y-m-d H:i:s', $legacy_ts);
                            $insert_data['updated_at'] = date('Y-m-d H:i:s', $legacy_ts);
                            $formats[] = '%s';
                            $formats[] = '%s';
                        }

                        $wpdb->insert($table, $insert_data, $formats);
                    }
                    $report['migrated']++;
                    $event_detail['migrated']++;
                }
            }

            $report['events'][] = $event_detail;
        }

        $report['has_more']    = ($args['offset'] + $args['batch_size']) < $total_events_with_legacy;
        $report['next_offset'] = $report['has_more'] ? ($args['offset'] + (int) $args['batch_size']) : null;

        return $report;
    }
}

/**
 * ============================================================================
 * واجهة الأدمن — تشغيل يدوي فقط، لا شيء تلقائي
 * ============================================================================
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=pge_event',
        'ترحيل بيانات RSVP القديمة',
        '🔄 ترحيل RSVP',
        'manage_options',
        'pge-rsvp-migration',
        'pge_render_rsvp_migration_page'
    );
});

function pge_render_rsvp_migration_page()
{
    if (!current_user_can('manage_options')) return;

    $report = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pge_rsvp_migration_action'])) {
        check_admin_referer('pge_rsvp_migration');

        $dry_run = ($_POST['pge_rsvp_migration_action'] === 'dry_run');
        $offset  = isset($_POST['pge_migration_offset']) ? absint($_POST['pge_migration_offset']) : 0;

        $report = pge_migrate_legacy_rsvp_meta([
            'dry_run'    => $dry_run,
            'batch_size' => 25,
            'offset'     => $offset,
        ]);
    }

    echo '<div class="wrap" style="direction:rtl; font-family:\'Segoe UI\',Tahoma;">';
    echo '<h1>🔄 ترحيل بيانات RSVP القديمة</h1>';

    echo '<div style="background:#fff8e1; border:1px solid #ffe082; border-radius:8px; padding:16px 20px; margin:16px 0; max-width:760px;">';
    echo '<strong>⚠️ قبل التنفيذ الفعلي:</strong> يُنصح بشدة بعمل نسخة احتياطية كاملة من قاعدة البيانات (جدول <code>' . esc_html($GLOBALS['wpdb']->prefix . 'pge_event_rsvps') . '</code> على الأقل) قبل الضغط على "تنفيذ الترحيل الفعلي". هذه الأداة لا تحذف أي بيانات قديمة من Post Meta ولا تُشغَّل تلقائياً — التشغيل يدوي بالكامل من هذه الصفحة فقط.';
    echo '</div>';

    echo '<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; max-width:760px;">';
    echo '<p style="color:#555;">تنقل الترحيل ردود الضيوف المخزَّنة قديماً في <code>_pge_rsvp_map</code> / <code>_pge_rsvp_records</code> إلى الجدول الحقيقي <code>wp_pge_event_rsvps</code> الذي تعتمد عليه لوحة التحكم حالياً. لا يُكرَّر أي صف موجود، ولا تُستبدَل بيانات جدول أحدث ببيانات قديمة.</p>';

    echo '<form method="post" style="margin-top:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">';
    wp_nonce_field('pge_rsvp_migration');
    echo '<input type="hidden" name="pge_migration_offset" value="0" />';
    echo '<button type="submit" name="pge_rsvp_migration_action" value="dry_run" class="button button-secondary">🔍 تشغيل تجريبي (Dry Run) — بدون أي تعديل</button>';
    echo '<button type="submit" name="pge_rsvp_migration_action" value="live_run" class="button button-primary" onclick="return confirm(\'هل أخذت نسخة احتياطية؟ سيتم تعديل قاعدة البيانات فعلياً.\');">⚡ تنفيذ الترحيل الفعلي (أول 25 مناسبة)</button>';
    echo '</form>';
    echo '</div>';

    if ($report !== null) {
        $mode_label = $report['dry_run'] ? '🔍 تشغيل تجريبي (لم يُعدَّل أي شيء)' : '⚡ تنفيذ فعلي (تم التعديل)';
        echo '<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; margin-top:20px; max-width:760px;">';
        echo '<h2 style="margin-top:0;">نتيجة الدفعة — ' . esc_html($mode_label) . '</h2>';
        echo '<table class="widefat striped" style="max-width:520px;"><tbody>';
        echo '<tr><td>إجمالي المناسبات التي تحمل بيانات قديمة</td><td><strong>' . (int) $report['total_events_with_legacy_data'] . '</strong></td></tr>';
        echo '<tr><td>مناسبات فُحصت في هذه الدفعة</td><td><strong>' . (int) $report['events_scanned'] . '</strong></td></tr>';
        echo '<tr><td>صفوف رد فُحصت</td><td><strong>' . (int) $report['scanned'] . '</strong></td></tr>';
        echo '<tr style="color:#16a34a;"><td>تم ترحيلها' . ($report['dry_run'] ? ' (ستُرحَّل)' : '') . '</td><td><strong>' . (int) $report['migrated'] . '</strong></td></tr>';
        echo '<tr><td>تم تخطيها (مطابقة أصلاً أو بيانات SQL أحدث)</td><td><strong>' . (int) $report['skipped'] . '</strong></td></tr>';
        echo '<tr style="color:#b45309;"><td>تعارضات (تحتاج مراجعة يدوية)</td><td><strong>' . (int) $report['conflicts'] . '</strong></td></tr>';
        echo '<tr style="color:#dc2626;"><td>غير صالحة (رقم/رد غير قابل للتفسير)</td><td><strong>' . (int) $report['invalid'] . '</strong></td></tr>';
        echo '</tbody></table>';

        if ($report['has_more']) {
            echo '<form method="post" style="margin-top:16px;">';
            wp_nonce_field('pge_rsvp_migration');
            echo '<input type="hidden" name="pge_migration_offset" value="' . (int) $report['next_offset'] . '" />';
            $next_label = $report['dry_run'] ? 'الدفعة التالية (تجريبي)' : 'الدفعة التالية (تنفيذ فعلي)';
            $next_action = $report['dry_run'] ? 'dry_run' : 'live_run';
            echo '<button type="submit" name="pge_rsvp_migration_action" value="' . esc_attr($next_action) . '" class="button">▶ ' . esc_html($next_label) . '</button>';
            echo '<p style="color:#888; font-size:12px; margin-top:6px;">تبقّى مناسبات لم تُفحَص بعد في هذه الدفعة — اضغط للمتابعة من حيث توقفت.</p>';
            echo '</form>';
        } else {
            echo '<p style="color:#16a34a; margin-top:12px;"><strong>✅ اكتمل فحص جميع المناسبات التي تحمل بيانات RSVP قديمة.</strong></p>';
        }

        if (!empty($report['conflicts']) || !empty($report['invalid'])) {
            echo '<details style="margin-top:16px;"><summary style="cursor:pointer; font-weight:bold;">تفاصيل حسب المناسبة</summary>';
            echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Event ID</th><th>مُرحَّل</th><th>مُتخطَّى</th><th>تعارض</th><th>غير صالح</th></tr></thead><tbody>';
            foreach ($report['events'] as $ev) {
                if ($ev['conflicts'] === 0 && $ev['invalid'] === 0) continue;
                echo '<tr><td><a href="' . esc_url(get_edit_post_link($ev['event_id'])) . '">#' . (int) $ev['event_id'] . '</a></td><td>' . (int) $ev['migrated'] . '</td><td>' . (int) $ev['skipped'] . '</td><td>' . (int) $ev['conflicts'] . '</td><td>' . (int) $ev['invalid'] . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        echo '</div>';
    }

    echo '</div>';
}
