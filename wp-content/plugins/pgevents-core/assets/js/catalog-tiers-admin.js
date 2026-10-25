(function () {
    'use strict';

    function initTierTabs(container) {
        var tabs = container.querySelectorAll('[role="tab"]');
        if (!tabs.length) {
            return;
        }

        function selectTab(tab, moveFocus) {
            var panelId = tab.getAttribute('aria-controls');
            var panel = document.getElementById(panelId);
            if (!panel) {
                return;
            }

            for (var i = 0; i < tabs.length; i++) {
                var candidate = tabs[i];
                var candidatePanel = document.getElementById(candidate.getAttribute('aria-controls'));
                var isSelected = candidate === tab;

                candidate.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                candidate.setAttribute('tabindex', isSelected ? '0' : '-1');
                if (candidatePanel) {
                    candidatePanel.hidden = !isSelected;
                }
            }

            var url = new URL(window.location.href);
            url.searchParams.set('tab', tab.getAttribute('data-pge-tier-tab') === 'features' ? 'features' : 'details');
            window.history.replaceState(null, '', url.toString());

            if (moveFocus) {
                tab.focus();
            }
        }

        function tabFromOffset(currentTab, offset) {
            var currentIndex = Array.prototype.indexOf.call(tabs, currentTab);
            return tabs[(currentIndex + offset + tabs.length) % tabs.length];
        }

        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function () {
                selectTab(this, false);
            });
            tabs[i].addEventListener('keydown', function (event) {
                var nextTab = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextTab = tabFromOffset(this, 1);
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextTab = tabFromOffset(this, -1);
                } else if (event.key === 'Home') {
                    nextTab = tabs[0];
                } else if (event.key === 'End') {
                    nextTab = tabs[tabs.length - 1];
                }

                if (nextTab) {
                    event.preventDefault();
                    selectTab(nextTab, true);
                }
            });
        }

        container.classList.add('pge-tier-tabs-ready');
        var requestedTab = new URL(window.location.href).searchParams.get('tab');
        selectTab(requestedTab === 'features' && tabs[1] ? tabs[1] : tabs[0], false);
    }

    function init() {
        var containers = document.querySelectorAll('.pge-catalog-tiers [data-pge-tier-tabs]');
        for (var i = 0; i < containers.length; i++) {
            initTierTabs(containers[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
