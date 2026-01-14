// assets/mon-events.js
(function () {
    function pad(n) { return String(n).padStart(2, '0'); }

    function formatLeft(ms) {
        if (ms <= 0) return 'بدأت المناسبة ✅';
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400); s %= 86400;
        var h = Math.floor(s / 3600); s %= 3600;
        var m = Math.floor(s / 60); s %= 60;
        var parts = [];
        if (d > 0) parts.push(d + ' يوم');
        parts.push(pad(h) + ':' + pad(m) + ':' + pad(s));
        return parts.join(' • ');
    }

    function initCountdown(el) {
        var ts = parseInt(el.getAttribute('data-ts') || '0', 10);
        if (!ts) return;

        var value = el.querySelector('[data-role="countdownValue"]');
        if (!value) return;

        function tick() {
            var now = Math.floor(Date.now() / 1000);
            var diff = (ts - now) * 1000;
            value.textContent = formatLeft(diff);
        }

        tick();
        setInterval(tick, 1000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-role="countdown"]').forEach(initCountdown);
    });
})();
