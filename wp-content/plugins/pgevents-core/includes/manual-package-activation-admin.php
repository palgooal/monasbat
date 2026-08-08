<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * صفحة "التفعيل اليدوي للباقات" — لوحة الإدارة (Admin-only)
 * ============================================================================
 * واجهة تفاعلية بحتة (بحث/معاينة/تأكيد) فوق نقاط نهاية AJAX المعرَّفة في
 * includes/manual-package-activation-ajax.php. هذا الملف **لا يحتوي أي منطق
 * تفعيل أو كتابة بيانات** — فقط HTML/CSS/JS لعرض النموذج واستهلاك تلك النقاط.
 *
 * الظهور: manage_options فقط (add_submenu_page + current_user_can داخل
 * الدالة نفسها كطبقة حماية مزدوجة)، تحت قائمة المناسبات — لا وصول لأي دور آخر.
 */

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=pge_event',
        'تفعيل يدوي للباقات',
        '🔑 تفعيل يدوي للباقات',
        'manage_options',
        'pge-manual-package-activation',
        'pge_render_manual_package_activation_page'
    );
});

function pge_render_manual_package_activation_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('غير مصرح');
    }

    $nonce = wp_create_nonce('pge_manual_pkg_activation');
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <div class="wrap" style="direction:rtl; font-family:'Segoe UI',Tahoma;">
        <h1>🔑 التفعيل اليدوي للباقات</h1>
        <p style="color:#666; max-width:760px;">
            أداة إدارية رسمية لحالات الدعم الفني، التعويض، عملاء VIP، الاختبار، تعافي فشل Webhook سلة، أو نقل الاشتراك.
            <strong>ليست بديلاً</strong> عن تكامل سلة — كل تفعيل هنا يستدعي بالضبط نفس خدمة التفعيل التي يستخدمها Webhook سلة، ويُسجَّل في سجل تدقيق مستقل.
        </p>

        <div class="mon-card" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:24px; max-width:760px; margin-top:16px;">

            <!-- 1) بحث المستخدم -->
            <div style="margin-bottom:22px;">
                <label style="display:block; font-weight:bold; margin-bottom:6px;">١. البحث عن المستخدم (بالاسم أو البريد الإلكتروني)</label>
                <input type="text" id="mpa-user-search" placeholder="اكتب حرفين على الأقل..." style="width:100%; max-width:420px; padding:8px 12px; border:1px solid #ccc; border-radius:6px;" autocomplete="off" />
                <div id="mpa-user-results" style="margin-top:8px; max-width:420px; border:1px solid #eee; border-radius:6px; display:none; max-height:220px; overflow:auto;"></div>
                <div id="mpa-selected-user" style="margin-top:10px; display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px;"></div>
            </div>

            <!-- 2) اختيار الباقة -->
            <div style="margin-bottom:22px;">
                <label style="display:block; font-weight:bold; margin-bottom:6px;">٢. اختيار الباقة</label>
                <select id="mpa-package-select" style="width:100%; max-width:420px; padding:8px 12px; border:1px solid #ccc; border-radius:6px;">
                    <option value="">-- جاري التحميل... --</option>
                </select>
            </div>

            <!-- 3) معاينة الباقة -->
            <div id="mpa-preview" style="display:none; margin-bottom:22px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;"></div>

            <!-- تحذير باقة فعالة -->
            <div id="mpa-active-warning" style="display:none; margin-bottom:22px; background:#fff7ed; border:1px solid #fdba74; border-radius:8px; padding:14px 16px; color:#9a3412;"></div>

            <!-- 4) سبب التفعيل -->
            <div style="margin-bottom:22px;">
                <label style="display:block; font-weight:bold; margin-bottom:6px;">٣. سبب التفعيل اليدوي (إلزامي)</label>
                <textarea id="mpa-reason" rows="3" placeholder="مثال: تعويض / هدية / نقل اشتراك / دعم فني / اختبار" style="width:100%; max-width:600px; padding:8px 12px; border:1px solid #ccc; border-radius:6px;"></textarea>
            </div>

            <div id="mpa-message" style="margin-bottom:14px;"></div>

            <!-- 5) زر واحد فقط -->
            <button type="button" id="mpa-activate-btn" class="button button-primary" style="background:#2563eb; border-color:#1d4ed8; padding:8px 22px; height:auto; font-weight:bold;">
                تفعيل الباقة
            </button>
        </div>
    </div>

    <script>
    (function () {
        var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        var state = {
            selectedUserId: null,
            selectedUserActive: false,
            packages: [],
            confirmOverride: false,
        };

        function post(action, extra) {
            var body = new FormData();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.keys(extra || {}).forEach(function (k) {
                body.append(k, extra[k]);
            });
            return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        // ── بحث المستخدم ─────────────────────────────────────────────
        var searchTimer = null;
        var searchInput = document.getElementById('mpa-user-search');
        var resultsBox = document.getElementById('mpa-user-results');
        var selectedBox = document.getElementById('mpa-selected-user');

        searchInput.addEventListener('input', function () {
            var term = searchInput.value.trim();
            clearTimeout(searchTimer);
            if (term.length < 2) {
                resultsBox.style.display = 'none';
                resultsBox.innerHTML = '';
                return;
            }
            searchTimer = setTimeout(function () {
                post('pge_manual_activation_search_users', { term: term }).then(function (res) {
                    if (!res.success) return;
                    var users = res.data.users || [];
                    if (!users.length) {
                        resultsBox.innerHTML = '<div style="padding:10px; color:#888;">لا نتائج</div>';
                        resultsBox.style.display = 'block';
                        return;
                    }
                    resultsBox.innerHTML = users.map(function (u) {
                        var badge = u.has_active ? '<span style="color:#d97706; font-size:11px;">لديه باقة فعالة (' + escapeHtml(u.active_source) + ')</span>' : '<span style="color:#16a34a; font-size:11px;">بلا باقة فعالة</span>';
                        return '<div class="mpa-user-row" data-id="' + u.id + '" data-active="' + (u.has_active ? '1' : '0') + '" data-label="' + escapeHtml(u.active_label) + '" data-source="' + escapeHtml(u.active_source) + '" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f1f1f1;">' +
                            '<strong>' + escapeHtml(u.display_name) + '</strong> — ' + escapeHtml(u.email) + '<br>' + badge +
                            '</div>';
                    }).join('');
                    resultsBox.style.display = 'block';
                });
            }, 300);
        });

        resultsBox.addEventListener('click', function (e) {
            var row = e.target.closest('.mpa-user-row');
            if (!row) return;
            state.selectedUserId = parseInt(row.getAttribute('data-id'), 10);
            state.selectedUserActive = row.getAttribute('data-active') === '1';
            var label = row.getAttribute('data-label');
            var source = row.getAttribute('data-source');

            selectedBox.style.display = 'block';
            selectedBox.innerHTML = '<strong>المستخدم المحدَّد:</strong> ' + row.querySelector('strong').textContent +
                (state.selectedUserActive ? ' — <span style="color:#d97706;">لديه باقة فعالة حالياً' + (label ? (' (' + escapeHtml(label) + ' / ' + escapeHtml(source) + ')') : '') + '</span>' : '');

            resultsBox.style.display = 'none';
            searchInput.value = '';
            state.confirmOverride = false;
            renderActiveWarning();
        });

        function renderActiveWarning() {
            var box = document.getElementById('mpa-active-warning');
            if (!state.selectedUserId || !state.selectedUserActive) {
                box.style.display = 'none';
                box.innerHTML = '';
                return;
            }
            box.style.display = 'block';
            box.innerHTML = '⚠️ <strong>هذا المستخدم لديه باقة فعالة حالياً.</strong> المتابعة ستستبدل باقته الحالية بالباقة المختارة أعلاه.<br>' +
                '<label style="display:inline-block; margin-top:8px; font-weight:normal;"><input type="checkbox" id="mpa-confirm-override"> أؤكد أنني أريد المتابعة رغم وجود باقة فعالة</label>';

            document.getElementById('mpa-confirm-override').addEventListener('change', function (e) {
                state.confirmOverride = e.target.checked;
            });
        }

        // ── تحميل قائمة الباقات ──────────────────────────────────────
        var packageSelect = document.getElementById('mpa-package-select');
        post('pge_manual_activation_list_packages', {}).then(function (res) {
            if (!res.success) return;
            state.packages = res.data.packages || [];
            packageSelect.innerHTML = '<option value="">-- اختر باقة --</option>' + state.packages.map(function (p, idx) {
                return '<option value="' + idx + '">' + escapeHtml(p.label) + '</option>';
            }).join('');
        });

        packageSelect.addEventListener('change', function () {
            var idx = packageSelect.value;
            var previewBox = document.getElementById('mpa-preview');
            if (idx === '') {
                previewBox.style.display = 'none';
                previewBox.innerHTML = '';
                return;
            }
            var pkg = state.packages[idx];
            var payload = { source: pkg.source };
            if (pkg.source === 'catalog') {
                payload.plan_id = pkg.plan_id;
                payload.tier_id = pkg.tier_id;
            } else {
                payload.plan_key = pkg.plan_key;
            }
            post('pge_manual_activation_preview', payload).then(function (res) {
                if (!res.success) {
                    previewBox.style.display = 'block';
                    previewBox.innerHTML = '<span style="color:#b91c1c;">تعذّرت المعاينة: ' + escapeHtml(res.data && res.data.message) + '</span>';
                    return;
                }
                var d = res.data;
                var rows = [];
                rows.push(['الاسم', d.name]);
                if (d.source === 'catalog') {
                    rows.push(['النوع', d.plan_type]);
                    rows.push(['حد المدعوين', d.guest_limit === null ? 'غير محدود' : d.guest_limit]);
                    rows.push(['نمط حصة المناسبات', d.event_quota_mode]);
                    rows.push(['حد المناسبات', d.event_quota_limit]);
                    rows.push(['رصيد دعوات', d.invitation_credit_limit]);
                    rows.push(['رصيد استبدال', d.replacement_credit_limit]);
                    if (d.price) rows.push(['السعر', d.price + ' ' + d.currency]);
                } else {
                    rows.push(['حد المدعوين', d.guest_limit]);
                    rows.push(['عدد المناسبات', d.events_count]);
                    rows.push(['حد الصور', d.host_photos]);
                    rows.push(['رسائل واتساب', d.wa_messages]);
                }
                var html = '<h4 style="margin-top:0;">معاينة الباقة قبل التفعيل</h4><table style="width:100%; border-collapse:collapse;">';
                rows.forEach(function (r) {
                    html += '<tr><td style="padding:4px 8px; color:#555; width:160px;">' + escapeHtml(r[0]) + '</td><td style="padding:4px 8px; font-weight:bold;">' + escapeHtml(r[1]) + '</td></tr>';
                });
                html += '</table>';
                var activeFeatures = (d.features || []).filter(function (f) {
                    return f.value === '1' || f.value === 'true' || f.value === 'yes';
                });
                if (activeFeatures.length) {
                    html += '<div style="margin-top:10px;"><strong>الميزات المفعّلة:</strong><br>' +
                        activeFeatures.map(function (f) { return '<span style="display:inline-block; background:#e0e7ff; color:#3730a3; border-radius:12px; padding:2px 10px; margin:3px 4px 0 0; font-size:12px;">' + escapeHtml(f.label) + '</span>'; }).join('') +
                        '</div>';
                }
                previewBox.style.display = 'block';
                previewBox.innerHTML = html;
            });
        });

        // ── التفعيل ──────────────────────────────────────────────────
        document.getElementById('mpa-activate-btn').addEventListener('click', function () {
            var msgBox = document.getElementById('mpa-message');
            msgBox.innerHTML = '';

            if (!state.selectedUserId) {
                msgBox.innerHTML = '<span style="color:#b91c1c;">اختر مستخدماً أولاً.</span>';
                return;
            }
            var idx = packageSelect.value;
            if (idx === '') {
                msgBox.innerHTML = '<span style="color:#b91c1c;">اختر باقة أولاً.</span>';
                return;
            }
            var reason = document.getElementById('mpa-reason').value.trim();
            if (!reason) {
                msgBox.innerHTML = '<span style="color:#b91c1c;">سبب التفعيل اليدوي إلزامي.</span>';
                return;
            }
            if (state.selectedUserActive && !state.confirmOverride) {
                msgBox.innerHTML = '<span style="color:#b91c1c;">يجب تأكيد المتابعة رغم وجود باقة فعالة (الصندوق أعلاه).</span>';
                return;
            }

            var pkg = state.packages[idx];
            var payload = {
                target_user_id: state.selectedUserId,
                source: pkg.source,
                reason: reason,
                confirm_override: state.confirmOverride ? 1 : 0,
            };
            if (pkg.source === 'catalog') {
                payload.plan_id = pkg.plan_id;
                payload.tier_id = pkg.tier_id;
            } else {
                payload.plan_key = pkg.plan_key;
            }

            var btn = document.getElementById('mpa-activate-btn');
            btn.disabled = true;
            btn.textContent = 'جاري التفعيل...';

            post('pge_manual_activation_activate', payload).then(function (res) {
                btn.disabled = false;
                btn.textContent = 'تفعيل الباقة';
                if (res.success) {
                    msgBox.innerHTML = '<div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#16a34a; padding:10px 14px; border-radius:6px;">✅ ' + escapeHtml(res.data.message) + '</div>';
                } else {
                    msgBox.innerHTML = '<div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:10px 14px; border-radius:6px;">❌ ' + escapeHtml(res.data && res.data.message) + '</div>';
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'تفعيل الباقة';
                msgBox.innerHTML = '<span style="color:#b91c1c;">فشل الاتصال بالخادم.</span>';
            });
        });
    })();
    </script>
    <?php
}
