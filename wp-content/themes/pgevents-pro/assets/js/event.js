(function () {
    const pad = (n) => String(n).padStart(2, '0');

    // ------------------------
    // Countdown
    // ------------------------
    document.querySelectorAll('[data-countdown]').forEach((wrap) => {
        const iso = wrap.getAttribute('data-countdown');
        if (!iso) return;

        const dEl = wrap.querySelector('.cd-days');
        const hEl = wrap.querySelector('.cd-hours');
        const mEl = wrap.querySelector('.cd-mins');
        const sEl = wrap.querySelector('.cd-secs');

        const target = new Date(iso);

        const tick = () => {
            const now = new Date();
            let diff = target - now;

            if (diff <= 0) {
                dEl.textContent = '0';
                hEl.textContent = '00';
                mEl.textContent = '00';
                sEl.textContent = '00';
                return;
            }

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            diff -= days * (1000 * 60 * 60 * 24);
            const hours = Math.floor(diff / (1000 * 60 * 60));
            diff -= hours * (1000 * 60 * 60);
            const mins = Math.floor(diff / (1000 * 60));
            diff -= mins * (1000 * 60);
            const secs = Math.floor(diff / 1000);

            dEl.textContent = String(days);
            hEl.textContent = pad(hours);
            mEl.textContent = pad(mins);
            sEl.textContent = pad(secs);
        };

        tick();
        setInterval(tick, 1000);
    });

    // ------------------------
    // Tabs
    // ------------------------
    const tabBtns = document.querySelectorAll('.event-tab-btn');
    const panels = {
        details: document.getElementById('event-tab-details'),
        album: document.getElementById('event-tab-album'),
        chat: document.getElementById('event-tab-chat'),
    };

    tabBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            const tab = btn.getAttribute('data-tab');

            tabBtns.forEach((b) => {
                b.classList.remove('bg-slate-900', 'text-white');
                b.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            });

            btn.classList.add('bg-slate-900', 'text-white');
            btn.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');

            Object.values(panels).forEach((p) => p && p.classList.add('hidden'));
            panels[tab] && panels[tab].classList.remove('hidden');
        });
    });

    // ------------------------
    // RSVP buttons (UI only)
    // ------------------------
    const replyInput = document.getElementById('rsvpReply');
    document.querySelectorAll('.rsvp-btn').forEach((b) => {
        b.addEventListener('click', () => {
            const v = b.getAttribute('data-rsvp') || 'yes';
            replyInput && (replyInput.value = v);

            document.querySelectorAll('.rsvp-btn').forEach((x) => {
                x.classList.remove('bg-emerald-600', 'text-white');
                x.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            });

            if (v === 'yes') {
                b.classList.add('bg-emerald-600', 'text-white');
                b.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            } else {
                b.classList.add('bg-slate-900', 'text-white');
                b.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            }
        });
    });

    // ------------------------
    // Modal QR
    // ------------------------
    const modal = document.getElementById('eventQrModal');
    document.querySelectorAll('.js-open-qr').forEach((btn) => {
        btn.addEventListener('click', () => {
            modal && modal.classList.remove('hidden');
        });
    });
    document.querySelectorAll('.js-close-qr').forEach((btn) => {
        btn.addEventListener('click', () => {
            modal && modal.classList.add('hidden');
        });
    });
    modal?.addEventListener('click', (e) => {
        if (e.target === modal || e.target?.classList?.contains('bg-slate-950/50')) {
            modal.classList.add('hidden');
        }
    });

    // ------------------------
    // Copy link
    // ------------------------
    document.querySelectorAll('.js-copy-link').forEach((btn) => {
        btn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(window.location.href);
                btn.textContent = 'تم النسخ ✅';
                setTimeout(() => (btn.textContent = 'نسخ رابط الدعوة'), 1400);
            } catch (_) { }
        });
    });

    // ------------------------
    // WhatsApp share
    // ------------------------
    document.querySelectorAll('.js-share-wa').forEach((btn) => {
        btn.addEventListener('click', () => {
            const text = encodeURIComponent(document.title + '\n' + window.location.href);
            window.open('https://wa.me/?text=' + text, '_blank', 'noopener');
        });
    });

    // ------------------------
    // Add to Calendar (ICS)
    // ------------------------
    function toICSDate(dt) {
        const y = dt.getUTCFullYear();
        const mo = pad(dt.getUTCMonth() + 1);
        const da = pad(dt.getUTCDate());
        const hh = pad(dt.getUTCHours());
        const mm = pad(dt.getUTCMinutes());
        const ss = pad(dt.getUTCSeconds());
        return `${y}${mo}${da}T${hh}${mm}${ss}Z`;
    }

    document.querySelectorAll('.js-add-to-calendar').forEach((btn) => {
        btn.addEventListener('click', () => {
            const title = btn.getAttribute('data-title') || document.title;
            const location = btn.getAttribute('data-location') || '';
            const startIso = btn.getAttribute('data-start');
            if (!startIso) return;

            const start = new Date(startIso);
            const end = new Date(start.getTime() + 2 * 60 * 60 * 1000);

            const ics = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//PGEVENTS//Event//AR',
                'BEGIN:VEVENT',
                `UID:${Date.now()}@pgevents`,
                `DTSTAMP:${toICSDate(new Date())}`,
                `DTSTART:${toICSDate(start)}`,
                `DTEND:${toICSDate(end)}`,
                `SUMMARY:${title}`,
                `LOCATION:${location}`,
                'END:VEVENT',
                'END:VCALENDAR',
            ].join('\r\n');

            const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.href = url;
            a.download = 'event.ics';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });
    });
})();
