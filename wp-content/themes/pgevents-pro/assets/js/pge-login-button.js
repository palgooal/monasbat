/**
 * PGE Login Button — قائمة الحساب المنسدلة (logged-in state)
 * تفاعل بسيط بدون أي مكتبة خارجية: فتح/إغلاق، إغلاق عند النقر خارجاً أو Escape أو اختيار عنصر،
 * وتنقّل بلوحة المفاتيح (Arrow/Home/End) حسب نمط WAI-ARIA menu button.
 * يدعم عدة نسخ من الويدجت في نفس الصفحة (كل نسخة مستقلة عبر [data-pge-account-menu]).
 */
(function () {
    'use strict';

    function getParts(wrapper) {
        return {
            trigger: wrapper.querySelector('[data-pge-account-trigger]'),
            menu: wrapper.querySelector('[data-pge-account-dropdown]'),
        };
    }

    function getItems(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('[data-pge-account-item]'));
    }

    function isOpen(trigger) {
        return !!trigger && trigger.getAttribute('aria-expanded') === 'true';
    }

    function closeMenu(wrapper) {
        var parts = getParts(wrapper);
        if (!parts.trigger || !parts.menu) return;
        parts.menu.classList.add('hidden');
        parts.trigger.setAttribute('aria-expanded', 'false');
        var chevron = parts.trigger.querySelector('.pge-account-chevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    function openMenu(wrapper, focusItemIndex) {
        var parts = getParts(wrapper);
        if (!parts.trigger || !parts.menu) return;
        parts.menu.classList.remove('hidden');
        parts.trigger.setAttribute('aria-expanded', 'true');
        var chevron = parts.trigger.querySelector('.pge-account-chevron');
        if (chevron) chevron.classList.add('rotate-180');

        if (typeof focusItemIndex === 'number') {
            var items = getItems(parts.menu);
            if (!items.length) return;
            var index = focusItemIndex < 0 ? items.length - 1 : focusItemIndex % items.length;
            items[index].focus();
        }
    }

    function initMenu(wrapper) {
        var parts = getParts(wrapper);
        var trigger = parts.trigger;
        var menu = parts.menu;
        if (!trigger || !menu) return;

        trigger.addEventListener('click', function () {
            if (isOpen(trigger)) {
                closeMenu(wrapper);
            } else {
                openMenu(wrapper);
            }
        });

        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Down') {
                e.preventDefault();
                openMenu(wrapper, 0);
            } else if (e.key === 'ArrowUp' || e.key === 'Up') {
                e.preventDefault();
                openMenu(wrapper, -1);
            } else if (e.key === 'Escape' || e.key === 'Esc') {
                closeMenu(wrapper);
            }
        });

        menu.addEventListener('keydown', function (e) {
            var items = getItems(menu);
            var currentIndex = items.indexOf(document.activeElement);

            switch (e.key) {
                case 'Escape':
                case 'Esc':
                    e.preventDefault();
                    closeMenu(wrapper);
                    trigger.focus();
                    break;
                case 'ArrowDown':
                case 'Down':
                    e.preventDefault();
                    if (items.length) items[(currentIndex + 1 + items.length) % items.length].focus();
                    break;
                case 'ArrowUp':
                case 'Up':
                    e.preventDefault();
                    if (items.length) items[(currentIndex - 1 + items.length) % items.length].focus();
                    break;
                case 'Home':
                    e.preventDefault();
                    if (items.length) items[0].focus();
                    break;
                case 'End':
                    e.preventDefault();
                    if (items.length) items[items.length - 1].focus();
                    break;
                case 'Tab':
                    // اترك التنقل الافتراضي يعمل، فقط أغلق القائمة بصرياً
                    closeMenu(wrapper);
                    break;
                default:
                    break;
            }
        });

        getItems(menu).forEach(function (item) {
            item.addEventListener('click', function () {
                closeMenu(wrapper);
            });
        });

        document.addEventListener('click', function (e) {
            if (!isOpen(trigger)) return;
            if (!wrapper.contains(e.target)) closeMenu(wrapper);
        });

        document.addEventListener('focusin', function (e) {
            if (!isOpen(trigger)) return;
            if (!wrapper.contains(e.target)) closeMenu(wrapper);
        });
    }

    function init() {
        var wrappers = document.querySelectorAll('[data-pge-account-menu]');
        wrappers.forEach(initMenu);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
