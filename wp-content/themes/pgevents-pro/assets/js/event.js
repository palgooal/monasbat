/**
 * ============================================================================
 * event.js — المصدر الوحيد لسلوك JavaScript في صفحة المناسبة (guest-facing).
 * ============================================================================
 * يُحمَّل مرة واحدة فقط عبر functions.php (gated بـ is_singular('pge_event')).
 * لا يوجد أي منطق سلوكي مكرر داخل الملفات: hero.php / tabs.php / rsvp.php —
 * هذه الملفات تمرر فقط بيانات/إعدادات عبر HTML data-* attributes، والسلوك
 * الفعلي (event listeners) موجود هنا حصرياً.
 *
 * Init guard: يمنع هذا الملف نفسه من التهيئة مرتين لو أُدرِج بالخطأ أكثر
 * من مرة في نفس الصفحة.
 */
(function () {
    'use strict';

    if (window.__pgeEventJsInitialized) return;
    window.__pgeEventJsInitialized = true;

    const pad = (n) => String(n).padStart(2, '0');

    // ==========================================================================
    // العد التنازلي (Countdown) — عنصر واحد أو أكثر يحملون [data-countdown]
    // ==========================================================================
    document.querySelectorAll('[data-countdown]').forEach((wrap) => {
        const iso = wrap.getAttribute('data-countdown');
        if (!iso) return;

        const dEl = wrap.querySelector('.cd-days');
        const hEl = wrap.querySelector('.cd-hours');
        const mEl = wrap.querySelector('.cd-mins');
        const sEl = wrap.querySelector('.cd-secs');
        if (!dEl || !hEl || !mEl || !sEl) return;

        const target = new Date(iso).getTime();

        function tick() {
            const diff = target - Date.now();

            if (diff <= 0) {
                dEl.textContent = '0';
                hEl.textContent = '00';
                mEl.textContent = '00';
                sEl.textContent = '00';
                return;
            }

            dEl.textContent = String(Math.floor(diff / 86400000));
            hEl.textContent = pad(Math.floor((diff % 86400000) / 3600000));
            mEl.textContent = pad(Math.floor((diff % 3600000) / 60000));
            sEl.textContent = pad(Math.floor((diff % 60000) / 1000));
        }

        tick();
        setInterval(tick, 1000);
    });

    // ==========================================================================
    // التبويبات (Details / Album / Chat)
    // ==========================================================================
    (function initTabs() {
        const btns = document.querySelectorAll('.event-tab-btn');
        if (!btns.length) return;

        const panels = {
            details: document.getElementById('event-tab-details'),
            album: document.getElementById('event-tab-album'),
            chat: document.getElementById('event-tab-chat'),
        };

        function activate(tab) {
            Object.keys(panels).forEach((key) => {
                if (!panels[key]) return;
                panels[key].classList.toggle('hidden', key !== tab);
            });

            btns.forEach((b) => {
                const active = b.dataset.tab === tab;
                b.setAttribute('aria-selected', active ? 'true' : 'false');

                if (active) {
                    b.className = 'event-tab-btn flex h-11 shrink-0 items-center rounded-xl bg-foreground px-5 text-sm font-bold text-white';
                } else {
                    // حالة القفل (disabled) تُقرأ مباشرة من خاصية disabled التي
                    // يضبطها PHP على الزر نفسه — لا حاجة لأي متغيّر JS إضافي
                    // (هذا هو إصلاح خطأ Temporal Dead Zone السابق: كان الكود
                    // القديم يعيد فحص شرط القفل عبر متغيّرات $can_album_photos/
                    // $can_album_video المُعرَّفة لاحقاً في الملف، رغم أن b.disabled
                    // يحمل نفس المعلومة أصلاً وبأمان).
                    b.className = 'event-tab-btn flex h-11 shrink-0 items-center rounded-xl border border-border bg-white px-5 text-sm font-semibold text-foreground/80 '
                        + (b.disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-secondary/40');
                }
            });
        }

        btns.forEach((b) => {
            b.addEventListener('click', () => {
                if (b.disabled) return;
                activate(b.dataset.tab);
            });
        });

        activate('details');
    })();

    // ==========================================================================
    // نافذة QR المكبّرة (Modal)
    // ==========================================================================
    (function initQrModal() {
        const modal = document.getElementById('eventQrModal');
        if (!modal) return;

        document.querySelectorAll('.js-open-qr').forEach((btn) => {
            btn.addEventListener('click', () => modal.classList.remove('hidden'));
        });
        document.querySelectorAll('.js-close-qr').forEach((btn) => {
            btn.addEventListener('click', () => modal.classList.add('hidden'));
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') modal.classList.add('hidden');
        });
    })();

    // ==========================================================================
    // نسخ رابط الدعوة (زر واحد أو أكثر يحملون .js-copy-link)
    // ==========================================================================
    document.querySelectorAll('.js-copy-link').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const txt = btn.getAttribute('data-copy') || window.location.href;
            if (!txt) return;

            try {
                await navigator.clipboard.writeText(txt);
            } catch (e) {
                const ta = document.createElement('textarea');
                ta.value = txt;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (_e2) { /* noop */ }
                ta.remove();
            }

            const orig = btn.innerHTML;
            btn.innerHTML = '<span aria-hidden="true">✅</span> تم النسخ';
            setTimeout(() => { btn.innerHTML = orig; }, 1500);
        });
    });

    // ==========================================================================
    // مشاركة واتساب — القيم تأتي من data-title / data-url على الزر نفسه
    // (PHP يمرر البيانات فقط، لا سلوكاً)
    // ==========================================================================
    document.querySelectorAll('.js-share-wa').forEach((btn) => {
        btn.addEventListener('click', () => {
            const title = btn.getAttribute('data-title') || document.title;
            const url = btn.getAttribute('data-url') || window.location.href;
            const text = encodeURIComponent('أنت مدعو: ' + title + '\n' + url);
            window.open('https://wa.me/?text=' + text, '_blank', 'noopener');
        });
    });

    // ==========================================================================
    // إضافة للتقويم (Google Calendar) — data-title / data-location / data-start
    // ==========================================================================
    document.querySelectorAll('.js-add-to-calendar').forEach((btn) => {
        btn.addEventListener('click', () => {
            const title = encodeURIComponent(btn.getAttribute('data-title') || document.title);
            const location = encodeURIComponent(btn.getAttribute('data-location') || '');
            const startRaw = btn.getAttribute('data-start') || '';
            if (!startRaw) return;

            const startDate = new Date(startRaw);
            const endDate = new Date(startDate.getTime() + 3 * 60 * 60 * 1000); // +3 ساعات

            function toGcalDate(d) {
                return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
            }

            const gcal = 'https://www.google.com/calendar/render?action=TEMPLATE'
                + '&text=' + title
                + '&dates=' + toGcalDate(startDate) + '/' + toGcalDate(endDate)
                + '&location=' + location;

            window.open(gcal, '_blank', 'noopener');
        });
    });

    // ==========================================================================
    // نموذج تأكيد الحضور RSVP — أزرار الرد + عدّاد المرافقين
    // ==========================================================================
    (function initRsvpForm() {
        const input = document.getElementById('rsvpReply');
        if (!input) return; // النموذج غير موجود في هذه الصفحة (مثلاً الضيف غير مؤهل)

        const btns = document.querySelectorAll('.rsvp-btn');
        const block = document.getElementById('rsvpCompanionsBlock');
        const cInput = document.getElementById('companionsInput');
        const cMinus = document.getElementById('companionsMinus');
        const cPlus = document.getElementById('companionsPlus');

        function setActive(val) {
            input.value = val;

            if (block) block.classList.toggle('hidden', val === 'no');

            btns.forEach((b) => {
                const v = b.getAttribute('data-rsvp');
                const active = (v === val);

                let cls = 'rsvp-btn flex h-16 flex-col items-center justify-center rounded-2xl text-sm font-extrabold transition-all active:scale-[.97] ';

                if (active && v === 'yes') {
                    cls += 'bg-primary text-white shadow-md ring-2 ring-primary';
                } else if (active && v === 'no') {
                    cls += 'bg-foreground text-white shadow-md ring-2 ring-foreground';
                } else if (v === 'yes') {
                    cls += 'border-2 border-border bg-white text-foreground hover:border-primary/40 hover:bg-primary/5';
                } else {
                    cls += 'border-2 border-border bg-white text-foreground hover:border-foreground/30 hover:bg-secondary/40';
                }

                b.className = cls;
            });
        }

        btns.forEach((b) => {
            b.addEventListener('click', () => setActive(b.getAttribute('data-rsvp')));
        });

        if (cMinus && cPlus && cInput) {
            cMinus.addEventListener('click', () => {
                const v = parseInt(cInput.value, 10) || 0;
                cInput.value = Math.max(0, v - 1);
            });
            cPlus.addEventListener('click', () => {
                const v = parseInt(cInput.value, 10) || 0;
                cInput.value = Math.min(20, v + 1);
            });
        }

        // التهيئة الأولية — القيمة المفضّلة تصل عبر data-pref-reply على قسم RSVP
        const section = document.getElementById('rsvp');
        const prefReply = (section && section.getAttribute('data-pref-reply')) || input.value || 'yes';
        setActive(prefReply);
    })();
})();
