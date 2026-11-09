(function () {
    'use strict';

    function initHeader(header) {
        var toggle = header.querySelector('[data-pge-native-menu-toggle]');
        var panel = header.querySelector('[data-pge-native-menu-panel]');
        if (!toggle || !panel) {
            return;
        }

        var mobileQuery = window.matchMedia('(max-width: 1024px)');

        function syncPanelAria() {
            var hidden = mobileQuery.matches && !panel.classList.contains('is-open');
            panel.setAttribute('aria-hidden', hidden ? 'true' : 'false');
        }

        document.documentElement.classList.add('pge-native-menu-ready');
        syncPanelAria();
        mobileQuery.addEventListener('change', syncPanelAria);

        function setOpen(open, restoreFocus) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.classList.toggle('is-open', open);
            panel.classList.toggle('is-open', open);
            syncPanelAria();
            if (!open && restoreFocus) {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(toggle.getAttribute('aria-expanded') !== 'true', false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.key === 'Esc') {
                if (toggle.getAttribute('aria-expanded') === 'true') {
                    event.preventDefault();
                    setOpen(false, true);
                }
            }
        });

        panel.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                setOpen(false, false);
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-pge-native-header]').forEach(initHeader);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
